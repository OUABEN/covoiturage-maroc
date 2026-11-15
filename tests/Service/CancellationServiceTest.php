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
use App\Exception\InvalidCancellationException;
use App\Service\CancellationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

class CancellationServiceTest extends KernelTestCase
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

    /**
     * @return array{0: Trip, 1: Booking, 2: User, 3: User, 4: \DateTimeImmutable}
     */
    private function createTripAndBooking(): array
    {
        $departureTime = new \DateTimeImmutable('2026-11-20 12:00:00');

        $driver = (new User())
            ->setEmail('driver.cancel@test.ma')
            ->setFirstName('Youssef')
            ->setLastName('Benali')
            ->setPhone('+212600000001')
            ->setPassword('hashed');
        $this->em->persist($driver);

        $passenger = (new User())
            ->setEmail('passenger.cancel@test.ma')
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

        $agadir = (new City())->setName('Agadir')->setLatitude('30.427755')->setLongitude('-9.598107');
        $marrakech = (new City())->setName('Marrakech')->setLatitude('31.629472')->setLongitude('-7.981084');
        $this->em->persist($agadir);
        $this->em->persist($marrakech);

        $trip = (new Trip())
            ->setDriver($driver)
            ->setVehicle($vehicle)
            ->setSeatsTotal(4)
            ->setStatus(TripStatus::PUBLISHED);

        $stop0 = (new TripStop())
            ->setCity($agadir)
            ->setStopOrder(0)
            ->setDepartureTime($departureTime)
            ->setPriceFromPrevious('0.00');

        $stop1 = (new TripStop())
            ->setCity($marrakech)
            ->setStopOrder(1)
            ->setArrivalTime($departureTime->modify('+3 hours'))
            ->setPriceFromPrevious('150.00');

        $trip->addStop($stop0)->addStop($stop1);
        $this->em->persist($stop0);
        $this->em->persist($stop1);
        $this->em->persist($trip);

        $booking = (new Booking())
            ->setTrip($trip)
            ->setPassenger($passenger)
            ->setFromStop($stop0)
            ->setToStop($stop1)
            ->setSeats(2)
            ->setPrice('300.00')
            ->setStatus(BookingStatus::CONFIRMED);
        $this->em->persist($booking);
        $this->em->flush();

        return [$trip, $booking, $driver, $passenger, $departureTime];
    }

    public function testPassengerCancelMoreThan24HoursBeforeDepartureRefunds100Percent(): void
    {
        [, $booking, , $passenger, $departureTime] = $this->createTripAndBooking();

        // 30 heures avant le départ (> 24h)
        $clock = new MockClock($departureTime->modify('-30 hours'));
        $service = new CancellationService($this->em, $clock);

        $service->cancelByPassenger($booking, $passenger);

        self::assertSame(BookingStatus::CANCELLED_BY_PASSENGER, $booking->getStatus());
        self::assertSame('300.00', $booking->getRefundAmount());
        self::assertEquals($clock->now(), $booking->getCancelledAt());
    }

    public function testPassengerCancelBetween24HoursAnd2HoursBeforeDepartureRefunds50Percent(): void
    {
        [, $booking, , $passenger, $departureTime] = $this->createTripAndBooking();

        // 10 heures avant le départ (entre 24h et 2h)
        $clock = new MockClock($departureTime->modify('-10 hours'));
        $service = new CancellationService($this->em, $clock);

        $service->cancelByPassenger($booking, $passenger);

        self::assertSame(BookingStatus::CANCELLED_BY_PASSENGER, $booking->getStatus());
        self::assertSame('150.00', $booking->getRefundAmount());
        self::assertEquals($clock->now(), $booking->getCancelledAt());
    }

    public function testPassengerCancelLessThan2HoursBeforeDepartureRefunds0Percent(): void
    {
        [, $booking, , $passenger, $departureTime] = $this->createTripAndBooking();

        // 45 minutes avant le départ (< 2h)
        $clock = new MockClock($departureTime->modify('-45 minutes'));
        $service = new CancellationService($this->em, $clock);

        $service->cancelByPassenger($booking, $passenger);

        self::assertSame(BookingStatus::CANCELLED_BY_PASSENGER, $booking->getStatus());
        self::assertSame('0.00', $booking->getRefundAmount());
        self::assertEquals($clock->now(), $booking->getCancelledAt());
    }

    public function testDriverCancelTripCancelsAllConfirmedBookingsWith100PercentRefund(): void
    {
        [$trip, $booking, $driver, , $departureTime] = $this->createTripAndBooking();

        $clock = new MockClock($departureTime->modify('-1 hour'));
        $service = new CancellationService($this->em, $clock);

        $service->cancelTripByDriver($trip, $driver);

        self::assertSame(TripStatus::CANCELLED, $trip->getStatus());
        self::assertEquals($clock->now(), $trip->getCancelledAt());

        $this->em->refresh($booking);
        self::assertSame(BookingStatus::CANCELLED_BY_DRIVER, $booking->getStatus());
        self::assertSame('300.00', $booking->getRefundAmount());
        self::assertEquals($clock->now(), $booking->getCancelledAt());
    }

    public function testOtherUserCannotCancelPassengerBooking(): void
    {
        [, $booking, $driver, , $departureTime] = $this->createTripAndBooking();

        $clock = new MockClock($departureTime->modify('-48 hours'));
        $service = new CancellationService($this->em, $clock);

        $this->expectException(InvalidCancellationException::class);
        $service->cancelByPassenger($booking, $driver);
    }

    public function testPassengerCancelAtExact24HoursBoundaryRefunds50Percent(): void
    {
        [, $booking, , $passenger, $departureTime] = $this->createTripAndBooking();

        // Exactement 86400 secondes (24h) avant le départ
        $clock = new MockClock($departureTime->modify('-24 hours'));
        $service = new CancellationService($this->em, $clock);

        $preview = $service->previewPassengerRefund($booking);
        self::assertSame(50, $preview['percentage']);
        self::assertSame('150.00', $preview['amount']);

        $service->cancelByPassenger($booking, $passenger);
        self::assertSame('150.00', $booking->getRefundAmount());
    }

    public function testPassengerCancelJustOver24HoursRefunds100Percent(): void
    {
        [, $booking, , $passenger, $departureTime] = $this->createTripAndBooking();

        // 24 heures et 1 seconde (86401 secondes)
        $clock = new MockClock($departureTime->modify('-24 hours -1 second'));
        $service = new CancellationService($this->em, $clock);

        $preview = $service->previewPassengerRefund($booking);
        self::assertSame(100, $preview['percentage']);
        self::assertSame('300.00', $preview['amount']);

        $service->cancelByPassenger($booking, $passenger);
        self::assertSame('300.00', $booking->getRefundAmount());
    }

    public function testPassengerCancelAtExact2HoursBoundaryRefunds50Percent(): void
    {
        [, $booking, , $passenger, $departureTime] = $this->createTripAndBooking();

        // Exactement 7200 secondes (2h) avant le départ
        $clock = new MockClock($departureTime->modify('-2 hours'));
        $service = new CancellationService($this->em, $clock);

        $preview = $service->previewPassengerRefund($booking);
        self::assertSame(50, $preview['percentage']);
        self::assertSame('150.00', $preview['amount']);

        $service->cancelByPassenger($booking, $passenger);
        self::assertSame('150.00', $booking->getRefundAmount());
    }

    public function testPassengerCancelJustUnder2HoursRefunds0Percent(): void
    {
        [, $booking, , $passenger, $departureTime] = $this->createTripAndBooking();

        // 1 heure 59 minutes et 59 secondes (7199 secondes)
        $clock = new MockClock($departureTime->modify('-2 hours +1 second'));
        $service = new CancellationService($this->em, $clock);

        $preview = $service->previewPassengerRefund($booking);
        self::assertSame(0, $preview['percentage']);
        self::assertSame('0.00', $preview['amount']);

        $service->cancelByPassenger($booking, $passenger);
        self::assertSame('0.00', $booking->getRefundAmount());
    }

    public function testRefundCalculationWithoutFloatPrecisionErrorUsingBcmath(): void
    {
        [, $booking, , $passenger, $departureTime] = $this->createTripAndBooking();
        $booking->setPrice('125.75');
        $this->em->flush();

        // Annulation à 50%
        $clock = new MockClock($departureTime->modify('-10 hours'));
        $service = new CancellationService($this->em, $clock);

        $preview = $service->previewPassengerRefund($booking);
        // 125.75 * 0.50 = 62.875 -> bcmul donne '62.87' sans arrondi flottant binaire imprévu
        self::assertSame('62.87', $preview['amount']);

        $service->cancelByPassenger($booking, $passenger);
        self::assertSame('62.87', $booking->getRefundAmount());
    }

    public function testDoubleCancellationByPassengerIsRejected(): void
    {
        [, $booking, , $passenger, $departureTime] = $this->createTripAndBooking();

        $clock = new MockClock($departureTime->modify('-10 hours'));
        $service = new CancellationService($this->em, $clock);

        $service->cancelByPassenger($booking, $passenger);
        self::assertSame(BookingStatus::CANCELLED_BY_PASSENGER, $booking->getStatus());

        $this->expectException(InvalidCancellationException::class);
        $this->expectExceptionMessage('Seule une réservation confirmée peut être annulée.');
        $service->cancelByPassenger($booking, $passenger);
    }
}
