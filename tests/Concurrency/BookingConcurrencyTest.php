<?php

declare(strict_types=1);

namespace App\Tests\Concurrency;

use App\Entity\Booking;
use App\Entity\City;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

class BookingConcurrencyTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
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

    public function testTwoParallelProcessesCompetingForLastSeatTenIterations(): void
    {
        $driver = (new User())
            ->setEmail('driver.conc@test.ma')
            ->setFirstName('Youssef')
            ->setLastName('Benali')
            ->setPhone('+212600000001')
            ->setPassword('hashed');
        $this->em->persist($driver);

        $passengerA = (new User())
            ->setEmail('passA.conc@test.ma')
            ->setFirstName('Mehdi')
            ->setLastName('Tazi')
            ->setPhone('+212600000002')
            ->setPassword('hashed');
        $this->em->persist($passengerA);

        $passengerB = (new User())
            ->setEmail('passB.conc@test.ma')
            ->setFirstName('Nadia')
            ->setLastName('Berrada')
            ->setPhone('+212600000003')
            ->setPassword('hashed');
        $this->em->persist($passengerB);

        $vehicle = (new Vehicle())
            ->setOwner($driver)
            ->setBrand('Dacia')
            ->setModel('Duster')
            ->setColor('Gris')
            ->setSeats(4);
        $this->em->persist($vehicle);

        $agadir = (new City())->setName('Agadir')->setLatitude('30.427755')->setLongitude('-9.598107');
        $marrakech = (new City())->setName('Marrakech')->setLatitude('31.629472')->setLongitude('-7.981084');
        $this->em->persist($agadir);
        $this->em->persist($marrakech);
        $this->em->flush();

        $workerScript = __DIR__.'/worker_book.php';

        for ($iteration = 1; $iteration <= 10; ++$iteration) {
            $depTime = new \DateTimeImmutable('+2 days 09:00');
            $arrTime = new \DateTimeImmutable('+2 days 12:00');

            $trip = (new Trip())
                ->setDriver($driver)
                ->setVehicle($vehicle)
                ->setSeatsTotal(1) // 1 seule place disponible sur ce trajet
                ->setStatus(TripStatus::PUBLISHED);

            $stop0 = (new TripStop())
                ->setCity($agadir)
                ->setStopOrder(0)
                ->setDepartureTime($depTime)
                ->setPriceFromPrevious('0.00');

            $stop1 = (new TripStop())
                ->setCity($marrakech)
                ->setStopOrder(1)
                ->setArrivalTime($arrTime)
                ->setPriceFromPrevious('120.00');

            $trip->addStop($stop0)->addStop($stop1);
            $this->em->persist($stop0);
            $this->em->persist($stop1);
            $this->em->persist($trip);
            $this->em->flush();

            $tripId = (string) $trip->getId();
            $fromId = (string) $stop0->getId();
            $toId = (string) $stop1->getId();

            $p1 = new Process([PHP_BINARY, $workerScript, $tripId, (string) $passengerA->getId(), $fromId, $toId]);
            $p2 = new Process([PHP_BINARY, $workerScript, $tripId, (string) $passengerB->getId(), $fromId, $toId]);

            $p1->start();
            $p2->start();

            $p1->wait();
            $p2->wait();

            self::assertTrue($p1->isSuccessful(), sprintf('Itération %d P1 erreur: %s', $iteration, $p1->getErrorOutput()));
            self::assertTrue($p2->isSuccessful(), sprintf('Itération %d P2 erreur: %s', $iteration, $p2->getErrorOutput()));

            $outputs = [$p1->getOutput(), $p2->getOutput()];

            $bookedCount = count(array_filter($outputs, static fn (string $out): bool => str_starts_with($out, 'BOOKED:')));
            $rejectedCount = count(array_filter($outputs, static fn (string $out): bool => str_starts_with($out, 'NOT_ENOUGH_SEATS:')));

            self::assertSame(1, $bookedCount, sprintf('Itération %d : exactement 1 réservation doit réussir (sorties : %s)', $iteration, implode(' | ', $outputs)));
            self::assertSame(1, $rejectedCount, sprintf('Itération %d : exactement 1 processus doit recevoir NotEnoughSeatsException (sorties : %s)', $iteration, implode(' | ', $outputs)));

            $this->em->clear();
            $confirmedInDb = $this->em->getRepository(Booking::class)->count([
                'trip' => $trip->getId(),
                'status' => BookingStatus::CONFIRMED,
            ]);
            self::assertSame(1, $confirmedInDb, sprintf('Itération %d : la base doit contenir exactement 1 réservation CONFIRMED.', $iteration));

            // Re-attacher les entités de référence après clear()
            $driver = $this->em->find(User::class, $driver->getId());
            $passengerA = $this->em->find(User::class, $passengerA->getId());
            $passengerB = $this->em->find(User::class, $passengerB->getId());
            $vehicle = $this->em->find(Vehicle::class, $vehicle->getId());
            $agadir = $this->em->find(City::class, $agadir->getId());
            $marrakech = $this->em->find(City::class, $marrakech->getId());
        }
    }

    public function testThreeParallelProcessesCompetingForTwoSeatsExactTwoSucceed(): void
    {
        $driver = (new User())
            ->setEmail('driver.conc3@test.ma')
            ->setFirstName('Karim')
            ->setLastName('El Amrani')
            ->setPhone('+212600000010')
            ->setPassword('hashed');
        $this->em->persist($driver);

        $passengerA = (new User())
            ->setEmail('passA3.conc@test.ma')
            ->setFirstName('Mehdi')
            ->setLastName('Tazi')
            ->setPhone('+212600000011')
            ->setPassword('hashed');
        $this->em->persist($passengerA);

        $passengerB = (new User())
            ->setEmail('passB3.conc@test.ma')
            ->setFirstName('Nadia')
            ->setLastName('Berrada')
            ->setPhone('+212600000012')
            ->setPassword('hashed');
        $this->em->persist($passengerB);

        $passengerC = (new User())
            ->setEmail('passC3.conc@test.ma')
            ->setFirstName('Samir')
            ->setLastName('Fassi')
            ->setPhone('+212600000013')
            ->setPassword('hashed');
        $this->em->persist($passengerC);

        $vehicle = (new Vehicle())
            ->setOwner($driver)
            ->setBrand('Peugeot')
            ->setModel('208')
            ->setColor('Bleu')
            ->setSeats(4);
        $this->em->persist($vehicle);

        $agadir = (new City())->setName('Agadir')->setLatitude('30.427755')->setLongitude('-9.598107');
        $marrakech = (new City())->setName('Marrakech')->setLatitude('31.629472')->setLongitude('-7.981084');
        $this->em->persist($agadir);
        $this->em->persist($marrakech);
        $this->em->flush();

        $workerScript = __DIR__.'/worker_book.php';

        for ($iteration = 1; $iteration <= 5; ++$iteration) {
            $depTime = new \DateTimeImmutable('+3 days 10:00');
            $arrTime = new \DateTimeImmutable('+3 days 13:00');

            $trip = (new Trip())
                ->setDriver($driver)
                ->setVehicle($vehicle)
                ->setSeatsTotal(2) // Exactement 2 places pour 3 candidats
                ->setStatus(TripStatus::PUBLISHED);

            $stop0 = (new TripStop())
                ->setCity($agadir)
                ->setStopOrder(0)
                ->setDepartureTime($depTime)
                ->setPriceFromPrevious('0.00');

            $stop1 = (new TripStop())
                ->setCity($marrakech)
                ->setStopOrder(1)
                ->setArrivalTime($arrTime)
                ->setPriceFromPrevious('130.00');

            $trip->addStop($stop0)->addStop($stop1);
            $this->em->persist($stop0);
            $this->em->persist($stop1);
            $this->em->persist($trip);
            $this->em->flush();

            $tripId = (string) $trip->getId();
            $fromId = (string) $stop0->getId();
            $toId = (string) $stop1->getId();

            $p1 = new Process([PHP_BINARY, $workerScript, $tripId, (string) $passengerA->getId(), $fromId, $toId]);
            $p2 = new Process([PHP_BINARY, $workerScript, $tripId, (string) $passengerB->getId(), $fromId, $toId]);
            $p3 = new Process([PHP_BINARY, $workerScript, $tripId, (string) $passengerC->getId(), $fromId, $toId]);

            $p1->start();
            $p2->start();
            $p3->start();

            $p1->wait();
            $p2->wait();
            $p3->wait();

            self::assertTrue($p1->isSuccessful(), sprintf('Itération %d P1: %s', $iteration, $p1->getErrorOutput()));
            self::assertTrue($p2->isSuccessful(), sprintf('Itération %d P2: %s', $iteration, $p2->getErrorOutput()));
            self::assertTrue($p3->isSuccessful(), sprintf('Itération %d P3: %s', $iteration, $p3->getErrorOutput()));

            $outputs = [$p1->getOutput(), $p2->getOutput(), $p3->getOutput()];

            $bookedCount = count(array_filter($outputs, static fn (string $out): bool => str_starts_with($out, 'BOOKED:')));
            $rejectedCount = count(array_filter($outputs, static fn (string $out): bool => str_starts_with($out, 'NOT_ENOUGH_SEATS:')));

            self::assertSame(2, $bookedCount, sprintf('Itération %d : exactement 2 réservations doivent réussir (sorties: %s)', $iteration, implode(' | ', $outputs)));
            self::assertSame(1, $rejectedCount, sprintf('Itération %d : exactement 1 réservation doit échouer par NotEnoughSeatsException (sorties: %s)', $iteration, implode(' | ', $outputs)));

            $this->em->clear();
            $confirmedInDb = $this->em->getRepository(Booking::class)->count([
                'trip' => $trip->getId(),
                'status' => BookingStatus::CONFIRMED,
            ]);
            self::assertSame(2, $confirmedInDb, sprintf('Itération %d : la base doit contenir exactement 2 réservations CONFIRMED.', $iteration));

            $driver = $this->em->find(User::class, $driver->getId());
            $passengerA = $this->em->find(User::class, $passengerA->getId());
            $passengerB = $this->em->find(User::class, $passengerB->getId());
            $passengerC = $this->em->find(User::class, $passengerC->getId());
            $vehicle = $this->em->find(Vehicle::class, $vehicle->getId());
            $agadir = $this->em->find(City::class, $agadir->getId());
            $marrakech = $this->em->find(City::class, $marrakech->getId());
        }
    }
}

