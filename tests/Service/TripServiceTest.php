<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Booking;
use App\Entity\City;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Exception\InvalidTripOperationException;
use App\Service\CancellationService;
use App\Service\TripService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

class TripServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TripService $tripService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->tripService = $container->get(TripService::class);

        $this->cleanDatabase();
    }

    private function cleanDatabase(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['review', 'booking', 'trip_stop', 'trip', 'vehicle', 'city', '`user`'] as $table) {
            $conn->executeStatement(sprintf('TRUNCATE TABLE %s', $table));
        }
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Crée un trajet Agadir (0) -> Marrakech (1) -> Casablanca (2) -> Rabat (3).
     *
     * @return array{0: Trip, 1: list<TripStop>, 2: User, 3: User}
     */
    private function createFourStopScenario(): array
    {
        $driver = (new User())
            ->setEmail('driver.trip@test.ma')
            ->setFirstName('Youssef')
            ->setLastName('Benali')
            ->setPhone('+212600000001')
            ->setPassword('hashed');
        $this->em->persist($driver);

        $passenger = (new User())
            ->setEmail('passenger.trip@test.ma')
            ->setFirstName('Mehdi')
            ->setLastName('Tazi')
            ->setPhone('+212600000002')
            ->setPassword('hashed');
        $this->em->persist($passenger);

        $vehicle = (new Vehicle())
            ->setOwner($driver)
            ->setBrand('Dacia')
            ->setModel('Duster')
            ->setColor('Gris')
            ->setSeats(4);
        $this->em->persist($vehicle);

        $trip = (new Trip())
            ->setDriver($driver)
            ->setVehicle($vehicle)
            ->setSeatsTotal(4)
            ->setStatus(TripStatus::PUBLISHED);

        $baseTime = new \DateTimeImmutable('2026-11-25 08:00:00');
        $cities = ['Agadir', 'Marrakech', 'Casablanca', 'Rabat'];
        $stops = [];

        foreach ($cities as $idx => $name) {
            $city = (new City())->setName($name)->setLatitude('31.000000')->setLongitude('-8.000000');
            $this->em->persist($city);

            $t = $baseTime->modify(sprintf('+%d hours', $idx * 3));
            $stop = (new TripStop())
                ->setCity($city)
                ->setStopOrder($idx)
                ->setArrivalTime(0 === $idx ? null : $t)
                ->setDepartureTime(3 === $idx ? null : $t)
                ->setPriceFromPrevious(0 === $idx ? '0.00' : '80.00');

            $trip->addStop($stop);
            $this->em->persist($stop);
            $stops[] = $stop;
        }

        $this->em->persist($trip);
        $this->em->flush();

        return [$trip, $stops, $driver, $passenger];
    }

    public function testUpdateStopTimeWithin30MinToleranceDoesNotTriggerFullRefundEligibility(): void
    {
        [, $stops, $driver] = $this->createFourStopScenario();

        $oldDep = $stops[0]->getDepartureTime();
        $exceedsTolerance = $this->tripService->updateStopTime(
            $stops[0],
            $driver,
            null,
            $oldDep->modify('+20 minutes')
        );

        self::assertFalse($exceedsTolerance);
    }

    public function testUpdateStopTimeBeyond30MinToleranceTriggers100PercentRefundEvenWithin2Hours(): void
    {
        [$trip, $stops, $driver, $passenger] = $this->createFourStopScenario();

        $booking = (new Booking())
            ->setTrip($trip)
            ->setPassenger($passenger)
            ->setFromStop($stops[0])
            ->setToStop($stops[2])
            ->setSeats(1)
            ->setPrice('160.00')
            ->setStatus(BookingStatus::CONFIRMED);
        $this->em->persist($booking);
        $this->em->flush();

        // Le conducteur décale le départ de +45 minutes (> 30 min)
        $oldDep = $stops[0]->getDepartureTime();
        $newDep = $oldDep->modify('+45 minutes');
        $exceedsTolerance = $this->tripService->updateStopTime($stops[0], $driver, null, $newDep);

        self::assertTrue($exceedsTolerance);

        // Même à 1h du nouveau départ (< 2h), le passager obtient 100 % de remboursement
        // calculé automatiquement par CancellationService à partir de originalDepartureTime vs departureTime (> 30 min)
        $clock = new MockClock($newDep->modify('-1 hour'));
        $cancellationService = new CancellationService($this->em, $clock);
        $cancellationService->cancelByPassenger($booking, $passenger);

        self::assertSame(BookingStatus::CANCELLED_BY_PASSENGER, $booking->getStatus());
        self::assertSame('160.00', $booking->getRefundAmount());
    }

    public function testRemoveMiddleStopOfThreeStopsOnRealMysqlReindexesWithoutUniqueViolation(): void
    {
        [$trip, $stops, $driver] = $this->createFourStopScenario();

        // Ramener d'abord de 4 à 3 arrêts : Agadir (0) -> Marrakech (1) -> Casablanca (2)
        $this->tripService->removeStop($stops[3], $driver);
        self::assertCount(3, $trip->getStops());

        // Supprimer l'arrêt du milieu Marrakech (stop_order = 1) parmi les 3 arrêts (0, 1, 2) sur MySQL réel
        $this->tripService->removeStop($stops[1], $driver);

        $this->em->clear();
        $reloadedTrip = $this->em->find(Trip::class, $trip->getId());
        self::assertNotNull($reloadedTrip);
        self::assertCount(2, $reloadedTrip->getStops());

        $orders = [];
        $cityNames = [];
        foreach ($reloadedTrip->getStops() as $s) {
            $orders[] = $s->getStopOrder();
            $cityNames[] = $s->getCity()?->getName();
        }

        self::assertSame([0, 1], $orders);
        self::assertSame(['Agadir', 'Casablanca'], $cityNames);
    }

    public function testRemoveStopTraversedByConfirmedBookingIsForbidden(): void
    {
        [$trip, $stops, $driver, $passenger] = $this->createFourStopScenario();

        // Réservation de Agadir (0) à Casablanca (2) -> traverse l'arrêt intermédiaire Marrakech (1) !
        $booking = (new Booking())
            ->setTrip($trip)
            ->setPassenger($passenger)
            ->setFromStop($stops[0])
            ->setToStop($stops[2])
            ->setSeats(1)
            ->setPrice('160.00')
            ->setStatus(BookingStatus::CONFIRMED);
        $trip->getBookings()->add($booking);
        $this->em->persist($booking);
        $this->em->flush();

        $this->expectException(InvalidTripOperationException::class);
        $this->expectExceptionMessage('Impossible de supprimer un arrêt utilisé ou traversé par une réservation confirmée.');
        $this->tripService->removeStop($stops[1], $driver);
    }

    public function testRemoveStopNotTraversedByConfirmedBookingSucceeds(): void
    {
        [$trip, $stops, $driver, $passenger] = $this->createFourStopScenario();

        // Réservation uniquement sur Agadir (0) -> Marrakech (1)
        $booking = (new Booking())
            ->setTrip($trip)
            ->setPassenger($passenger)
            ->setFromStop($stops[0])
            ->setToStop($stops[1])
            ->setSeats(1)
            ->setPrice('80.00')
            ->setStatus(BookingStatus::CONFIRMED);
        $trip->getBookings()->add($booking);
        $this->em->persist($booking);
        $this->em->flush();

        // L'arrêt final Rabat (3) n'est traversé par aucune réservation CONFIRMED : sa suppression est autorisée
        $this->tripService->removeStop($stops[3], $driver);

        self::assertCount(3, $trip->getStops());
    }

    public function testPublishValidMultiStopTripSucceedsAndInitializesOriginalStopTimes(): void
    {
        [$trip, $stops] = $this->createFourStopScenario();

        $published = $this->tripService->publish($trip);

        self::assertSame(TripStatus::PUBLISHED, $published->getStatus());
        self::assertEquals($stops[0]->getDepartureTime(), $stops[0]->getOriginalDepartureTime());
        self::assertEquals($stops[1]->getArrivalTime(), $stops[1]->getOriginalArrivalTime());
    }

    public function testPublishRejectsVehicleNotOwnedByDriver(): void
    {
        [$trip, , , $passenger] = $this->createFourStopScenario();
        $trip->setDriver($passenger);

        $this->expectException(InvalidTripOperationException::class);
        $this->expectExceptionMessage('Le véhicule sélectionné n\'appartient pas au conducteur.');
        $this->tripService->publish($trip);
    }

    public function testPublishRejectsSeatsTotalExceedingVehicleSeats(): void
    {
        [$trip] = $this->createFourStopScenario();
        $trip->setSeatsTotal(6);

        $this->expectException(InvalidTripOperationException::class);
        $this->expectExceptionMessage('Le nombre de places proposées doit être compris entre 1 et la capacité du véhicule.');
        $this->tripService->publish($trip);
    }

    public function testCompletePublishedTripMarksItCompleted(): void
    {
        [$trip, , $driver] = $this->createFourStopScenario();

        $completed = $this->tripService->complete($trip, $driver);

        self::assertSame(TripStatus::COMPLETED, $completed->getStatus());
    }
}
