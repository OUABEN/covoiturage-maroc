<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Booking;
use App\Entity\City;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Repository\TripRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TripRepositorySearchTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TripRepository $tripRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->tripRepository = $container->get(TripRepository::class);

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

    public function testSearchFindsSubPortionCalculatesExactPortionPriceAndAvailableSeats(): void
    {
        $driver = (new User())
            ->setEmail('driver.search@test.ma')
            ->setFirstName('Youssef')
            ->setLastName('Benali')
            ->setPhone('+212600000001')
            ->setPassword('hashed');
        $passenger = (new User())
            ->setEmail('pass.search@test.ma')
            ->setFirstName('Mehdi')
            ->setLastName('Tazi')
            ->setPhone('+212600000002')
            ->setPassword('hashed');
        $this->em->persist($driver);
        $this->em->persist($passenger);

        $vehicle = (new Vehicle())
            ->setOwner($driver)
            ->setBrand('Peugeot')
            ->setModel('3008')
            ->setColor('Noir')
            ->setSeats(4);
        $this->em->persist($vehicle);

        $agadir = (new City())->setName('Agadir')->setLatitude('30.427755')->setLongitude('-9.598107');
        $essaouira = (new City())->setName('Essaouira')->setLatitude('31.508493')->setLongitude('-9.759504');
        $safi = (new City())->setName('Safi')->setLatitude('32.299390')->setLongitude('-9.237180');
        $elJadida = (new City())->setName('El Jadida')->setLatitude('33.231633')->setLongitude('-8.500712');
        foreach ([$agadir, $essaouira, $safi, $elJadida] as $city) {
            $this->em->persist($city);
        }

        $targetDate = new \DateTimeImmutable('2026-12-01');

        // Trajet Agadir (0) -> Essaouira (1, 80 MAD) -> Safi (2, 60 MAD) -> El Jadida (3, 70 MAD), 4 places
        $trip = (new Trip())
            ->setDriver($driver)
            ->setVehicle($vehicle)
            ->setSeatsTotal(4)
            ->setStatus(TripStatus::PUBLISHED);

        $s0 = (new TripStop())->setCity($agadir)->setStopOrder(0)->setDepartureTime($targetDate->setTime(8, 0))->setPriceFromPrevious('0.00');
        $s1 = (new TripStop())->setCity($essaouira)->setStopOrder(1)->setArrivalTime($targetDate->setTime(10, 30))->setDepartureTime($targetDate->setTime(10, 45))->setPriceFromPrevious('80.00');
        $s2 = (new TripStop())->setCity($safi)->setStopOrder(2)->setArrivalTime($targetDate->setTime(12, 45))->setDepartureTime($targetDate->setTime(13, 0))->setPriceFromPrevious('60.00');
        $s3 = (new TripStop())->setCity($elJadida)->setStopOrder(3)->setArrivalTime($targetDate->setTime(15, 0))->setPriceFromPrevious('70.00');

        foreach ([$s0, $s1, $s2, $s3] as $stop) {
            $trip->addStop($stop);
            $this->em->persist($stop);
        }
        $this->em->persist($trip);

        // Réservation existante de 3 places sur Essaouira (1) -> Safi (2)
        $booking = (new Booking())
            ->setTrip($trip)
            ->setPassenger($passenger)
            ->setFromStop($s1)
            ->setToStop($s2)
            ->setSeats(3)
            ->setPrice('180.00')
            ->setStatus(BookingStatus::CONFIRMED);
        $this->em->persist($booking);
        $this->em->flush();

        // 1) Recherche sur la portion intermédiaire Essaouira -> El Jadida :
        // Prix = 60.00 + 70.00 = 130.00 MAD, Places disponibles = 4 - 3 = 1
        $results = $this->tripRepository->search($essaouira, $elJadida, $targetDate);
        self::assertCount(1, $results);
        self::assertSame($trip->getId(), $results[0]->trip->getId());
        self::assertSame('130.00', $results[0]->portionPrice);
        self::assertSame(1, $results[0]->availableSeats);

        // 2) Recherche en sens inverse (El Jadida -> Agadir) : 0 résultat (stopOrder non croissant)
        $reverseResults = $this->tripRepository->search($elJadida, $agadir, $targetDate);
        self::assertCount(0, $reverseResults);

        // 3) Recherche sur Safi -> El Jadida (où les 3 passagers d'Essaouira sont descendus) : 4 places disponibles !
        $lastLegResults = $this->tripRepository->search($safi, $elJadida, $targetDate);
        self::assertCount(1, $lastLegResults);
        self::assertSame('70.00', $lastLegResults[0]->portionPrice);
        self::assertSame(4, $lastLegResults[0]->availableSeats);
    }
}
