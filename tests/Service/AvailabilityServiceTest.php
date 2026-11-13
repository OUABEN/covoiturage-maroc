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
use App\Service\AvailabilityService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class AvailabilityServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AvailabilityService $availabilityService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->availabilityService = $container->get(AvailabilityService::class);

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
     * Crée un trajet à 4 étapes : Agadir (0) -> Essaouira (1) -> Safi (2) -> El Jadida (3).
     *
     * @return array{0: Trip, 1: list<TripStop>, 2: list<User>}
     */
    private function createFourStopTrip(int $seatsTotal = 4): array
    {
        $driver = (new User())
            ->setEmail('driver@test.ma')
            ->setFirstName('Youssef')
            ->setLastName('Benali')
            ->setPhone('+212600000001')
            ->setPassword('hashed');
        $this->em->persist($driver);

        $passengers = [];
        for ($i = 1; $i <= 3; ++$i) {
            $p = (new User())
                ->setEmail(sprintf('p%d@test.ma', $i))
                ->setFirstName('Passager')
                ->setLastName((string) $i)
                ->setPhone(sprintf('+21260000001%d', $i))
                ->setPassword('hashed');
            $this->em->persist($p);
            $passengers[] = $p;
        }

        $vehicle = (new Vehicle())
            ->setOwner($driver)
            ->setBrand('Dacia')
            ->setModel('Duster')
            ->setColor('Gris')
            ->setSeats(4);
        $this->em->persist($vehicle);

        $citiesNames = ['Agadir', 'Essaouira', 'Safi', 'El Jadida'];
        $stops = [];

        $trip = (new Trip())
            ->setDriver($driver)
            ->setVehicle($vehicle)
            ->setSeatsTotal($seatsTotal)
            ->setStatus(TripStatus::PUBLISHED);

        $baseDate = new \DateTimeImmutable('+2 days 08:00');

        foreach ($citiesNames as $idx => $name) {
            $city = (new City())
                ->setName($name)
                ->setLatitude('31.000000')
                ->setLongitude('-9.000000');
            $this->em->persist($city);

            $stop = (new TripStop())
                ->setCity($city)
                ->setStopOrder($idx)
                ->setArrivalTime(0 === $idx ? null : $baseDate->modify(sprintf('+%d hours', $idx * 2)))
                ->setDepartureTime(3 === $idx ? null : $baseDate->modify(sprintf('+%d hours', $idx * 2)))
                ->setPriceFromPrevious(0 === $idx ? '0.00' : '80.00');

            $trip->addStop($stop);
            $this->em->persist($stop);
            $stops[] = $stop;
        }

        $this->em->persist($trip);
        $this->em->flush();

        return [$trip, $stops, $passengers];
    }

    private function addBooking(
        Trip $trip,
        User $passenger,
        TripStop $from,
        TripStop $to,
        int $seats,
        BookingStatus $status = BookingStatus::CONFIRMED,
    ): Booking {
        $booking = (new Booking())
            ->setTrip($trip)
            ->setPassenger($passenger)
            ->setFromStop($from)
            ->setToStop($to)
            ->setSeats($seats)
            ->setPrice('100.00')
            ->setStatus($status);

        $this->em->persist($booking);
        $this->em->flush();

        return $booking;
    }

    public function testDisjointSegmentsReuseSameSeat(): void
    {
        [$trip, $stops, $passengers] = $this->createFourStopTrip(4);

        // Passager 1 réserve 3 places sur Agadir (0) -> Essaouira (1)
        $this->addBooking($trip, $passengers[0], $stops[0], $stops[1], 3);
        // Passager 2 réserve ces mêmes 3 places sur Essaouira (1) -> Safi (2) (segments disjoints)
        $this->addBooking($trip, $passengers[1], $stops[1], $stops[2], 3);

        // Sur [0 -> 1] : 1 place restante
        self::assertSame(1, $this->availabilityService->availableSeats($trip, $stops[0], $stops[1]));
        // Sur [1 -> 2] : 1 place restante (les 3 places libérées à Essaouira ont été réutilisées)
        self::assertSame(1, $this->availabilityService->availableSeats($trip, $stops[1], $stops[2]));
        // Sur [2 -> 3] : 4 places restantes
        self::assertSame(4, $this->availabilityService->availableSeats($trip, $stops[2], $stops[3]));
        // Sur le trajet complet [0 -> 3] : max(3, 3, 0) = 3 occupées => 1 disponible
        self::assertSame(1, $this->availabilityService->availableSeats($trip, $stops[0], $stops[3]));
    }

    public function testOverlappingBookingsTakeMaxSegmentOccupancy(): void
    {
        [$trip, $stops, $passengers] = $this->createFourStopTrip(4);

        // Passager 1 réserve 2 places sur Agadir (0) -> Safi (2) (segments 0 et 1)
        $this->addBooking($trip, $passengers[0], $stops[0], $stops[2], 2);
        // Passager 2 réserve 1 place sur Essaouira (1) -> El Jadida (3) (segments 1 et 2)
        $this->addBooking($trip, $passengers[1], $stops[1], $stops[3], 1);

        // Occupation par segment : S0 = 2, S1 = 2+1 = 3 (chevauchement), S2 = 1
        self::assertSame(2, $this->availabilityService->availableSeats($trip, $stops[0], $stops[1]));
        self::assertSame(1, $this->availabilityService->availableSeats($trip, $stops[1], $stops[2]));
        self::assertSame(3, $this->availabilityService->availableSeats($trip, $stops[2], $stops[3]));
        // Portion [0 -> 3] : goulot d'étranglement sur S1 (3 occupées) => 4 - 3 = 1 place disponible
        self::assertSame(1, $this->availabilityService->availableSeats($trip, $stops[0], $stops[3]));
    }

    public function testFullTripSegmentReturnsZeroAvailableSeats(): void
    {
        [$trip, $stops, $passengers] = $this->createFourStopTrip(4);

        // Le segment intermédiaire Essaouira (1) -> Safi (2) est complet (4 places)
        $this->addBooking($trip, $passengers[0], $stops[1], $stops[2], 4);

        // Toute portion traversant le segment [1, 2[ a 0 place disponible
        self::assertSame(0, $this->availabilityService->availableSeats($trip, $stops[1], $stops[2]));
        self::assertSame(0, $this->availabilityService->availableSeats($trip, $stops[0], $stops[2]));
        self::assertSame(0, $this->availabilityService->availableSeats($trip, $stops[1], $stops[3]));
        self::assertSame(0, $this->availabilityService->availableSeats($trip, $stops[0], $stops[3]));

        // En revanche, les segments avant [0 -> 1] et après [2 -> 3] restent entièrement libres !
        self::assertSame(4, $this->availabilityService->availableSeats($trip, $stops[0], $stops[1]));
        self::assertSame(4, $this->availabilityService->availableSeats($trip, $stops[2], $stops[3]));
    }

    public function testSeatFreedAtIntermediateStopAndCancelledBookingIgnored(): void
    {
        [$trip, $stops, $passengers] = $this->createFourStopTrip(3);

        // Passager 1 prend 1 place sur tout le trajet [0 -> 3]
        $this->addBooking($trip, $passengers[0], $stops[0], $stops[3], 1);
        // Passager 2 prend 2 places mais descend à l'étape intermédiaire Essaouira (1)
        $this->addBooking($trip, $passengers[1], $stops[0], $stops[1], 2);
        // Une réservation annulée sur [1 -> 3] ne doit pas compter
        $this->addBooking($trip, $passengers[2], $stops[1], $stops[3], 2, BookingStatus::CANCELLED_BY_PASSENGER);

        // Sur [0 -> 1], le véhicule est plein (1 + 2 = 3/3) => 0 place
        self::assertSame(0, $this->availabilityService->availableSeats($trip, $stops[0], $stops[1]));
        // À partir d'Essaouira [1 -> 3], les 2 places de Passager 2 sont libérées => 3 - 1 = 2 places disponibles
        self::assertSame(2, $this->availabilityService->availableSeats($trip, $stops[1], $stops[3]));
    }
}
