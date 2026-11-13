<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\City;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Exception\InvalidBookingException;
use App\Exception\NotEnoughSeatsException;
use App\Service\AvailabilityService;
use App\Service\BookingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class BookingServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BookingService $bookingService;
    private AvailabilityService $availabilityService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->bookingService = $container->get(BookingService::class);
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
     * @return array{0: Trip, 1: list<TripStop>, 2: User, 3: User}
     */
    private function createFixtureTrip(
        int $seatsTotal = 3,
        int $vehicleSeats = 4,
        TripStatus $status = TripStatus::PUBLISHED,
        string $departureOffset = '+2 days',
    ): array {
        $driver = (new User())
            ->setEmail('driver@covoiturage.ma')
            ->setFirstName('Youssef')
            ->setLastName('Benali')
            ->setPhone('+212600000001')
            ->setPassword('hashed');
        $this->em->persist($driver);

        $passenger = (new User())
            ->setEmail('passenger@covoiturage.ma')
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
            ->setSeats($vehicleSeats);
        $this->em->persist($vehicle);

        $trip = (new Trip())
            ->setDriver($driver)
            ->setVehicle($vehicle)
            ->setSeatsTotal($seatsTotal)
            ->setStatus($status);

        $baseTime = (new \DateTimeImmutable($departureOffset))->setTime(8, 0);
        $stopsSpec = [
            ['name' => 'Agadir', 'price' => '0.00', 'offsetHours' => 0],
            ['name' => 'Marrakech', 'price' => '120.50', 'offsetHours' => 3],
            ['name' => 'Casablanca', 'price' => '99.75', 'offsetHours' => 6],
        ];

        $stops = [];
        foreach ($stopsSpec as $idx => $spec) {
            $city = (new City())
                ->setName($spec['name'])
                ->setLatitude('31.500000')
                ->setLongitude('-8.000000');
            $this->em->persist($city);

            $time = $baseTime->modify(sprintf('+%d hours', $spec['offsetHours']));
            $stop = (new TripStop())
                ->setCity($city)
                ->setStopOrder($idx)
                ->setArrivalTime(0 === $idx ? null : $time)
                ->setDepartureTime(2 === $idx ? null : $time)
                ->setPriceFromPrevious($spec['price']);

            $trip->addStop($stop);
            $this->em->persist($stop);
            $stops[] = $stop;
        }

        $this->em->persist($trip);
        $this->em->flush();

        return [$trip, $stops, $driver, $passenger];
    }

    public function testBookSuccessWithExactBcmathPriceAndDecrementsAvailability(): void
    {
        [$trip, $stops, , $passenger] = $this->createFixtureTrip(3);

        // Réservation de 2 places sur Agadir (0) -> Casablanca (2) : (120.50 + 99.75) * 2 = 440.50 MAD
        $booking = $this->bookingService->book($trip, $passenger, $stops[0], $stops[2], 2);

        self::assertNotNull($booking->getId());
        self::assertSame(BookingStatus::CONFIRMED, $booking->getStatus());
        self::assertSame(2, $booking->getSeats());
        self::assertSame('440.50', $booking->getPrice());
        self::assertSame(1, $this->availabilityService->availableSeats($trip, $stops[0], $stops[2]));
    }

    public function testBookThrowsNotEnoughSeatsExceptionWhenExceedingSegmentCapacity(): void
    {
        [$trip, $stops, , $passenger] = $this->createFixtureTrip(2);

        $this->bookingService->book($trip, $passenger, $stops[0], $stops[1], 2);

        $this->expectException(NotEnoughSeatsException::class);
        $this->bookingService->book($trip, $passenger, $stops[0], $stops[2], 1);
    }

    public function testDriverCannotBookOwnTrip(): void
    {
        [$trip, $stops, $driver] = $this->createFixtureTrip(3);

        $this->expectException(InvalidBookingException::class);
        $this->expectExceptionMessage('Le conducteur ne peut pas réserver son propre trajet.');
        $this->bookingService->book($trip, $driver, $stops[0], $stops[1], 1);
    }

    public function testBookRejectsInvalidStopOrder(): void
    {
        [$trip, $stops, , $passenger] = $this->createFixtureTrip(3);

        $this->expectException(InvalidBookingException::class);
        $this->bookingService->book($trip, $passenger, $stops[2], $stops[0], 1);
    }

    public function testBookRejectsNonPublishedTrip(): void
    {
        [$trip, $stops, , $passenger] = $this->createFixtureTrip(3, 4, TripStatus::CANCELLED);

        $this->expectException(InvalidBookingException::class);
        $this->bookingService->book($trip, $passenger, $stops[0], $stops[1], 1);
    }

    public function testBookRejectsPastDeparture(): void
    {
        [$trip, $stops, , $passenger] = $this->createFixtureTrip(3, 4, TripStatus::PUBLISHED, '-2 days');

        $this->expectException(InvalidBookingException::class);
        $this->bookingService->book($trip, $passenger, $stops[0], $stops[1], 1);
    }

    public function testBookRejectsTripExceedingVehicleSeats(): void
    {
        [$trip, $stops, , $passenger] = $this->createFixtureTrip(5, 4);

        $this->expectException(InvalidBookingException::class);
        $this->bookingService->book($trip, $passenger, $stops[0], $stops[1], 1);
    }
}
