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
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DatabaseIntegrityAndNonOverbookingTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();
        $this->cleanDatabase();
    }

    private function cleanDatabase(): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['review', 'booking', 'trip_stop', 'trip', 'vehicle', 'city', '`user`'] as $table) {
            $this->connection->executeStatement(sprintf('TRUNCATE TABLE %s', $table));
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testSqlProvesNoSegmentIsOverbookedAndNoBookingIsOrphan(): void
    {
        // 1. Create realistic multi-segment trips with concurrent bookings
        $driver = (new User())
            ->setEmail('driver.integrity@test.ma')
            ->setFirstName('Rachid')
            ->setLastName('Alami')
            ->setPhone('+212600112233')
            ->setPassword('hashed');
        $this->em->persist($driver);

        $pass1 = (new User())
            ->setEmail('p1.integrity@test.ma')
            ->setFirstName('Mehdi')
            ->setLastName('Tazi')
            ->setPhone('+212600112234')
            ->setPassword('hashed');
        $pass2 = (new User())
            ->setEmail('p2.integrity@test.ma')
            ->setFirstName('Nadia')
            ->setLastName('Berrada')
            ->setPhone('+212600112235')
            ->setPassword('hashed');
        $this->em->persist($pass1);
        $this->em->persist($pass2);

        $vehicle = (new Vehicle())
            ->setOwner($driver)
            ->setBrand('Volkswagen')
            ->setModel('Golf')
            ->setColor('Gris')
            ->setSeats(4);
        $this->em->persist($vehicle);

        $agadir = (new City())->setName('Agadir')->setLatitude('30.42')->setLongitude('-9.59');
        $marrakech = (new City())->setName('Marrakech')->setLatitude('31.62')->setLongitude('-7.98');
        $casa = (new City())->setName('Casablanca')->setLatitude('33.57')->setLongitude('-7.58');
        $this->em->persist($agadir);
        $this->em->persist($marrakech);
        $this->em->persist($casa);

        $trip = (new Trip())
            ->setDriver($driver)
            ->setVehicle($vehicle)
            ->setSeatsTotal(3)
            ->setStatus(TripStatus::PUBLISHED);

        $stop0 = (new TripStop())->setCity($agadir)->setStopOrder(0)->setDepartureTime(new \DateTimeImmutable('+2 days 08:00'))->setPriceFromPrevious('0.00');
        $stop1 = (new TripStop())->setCity($marrakech)->setStopOrder(1)->setArrivalTime(new \DateTimeImmutable('+2 days 11:00'))->setDepartureTime(new \DateTimeImmutable('+2 days 11:30'))->setPriceFromPrevious('120.00');
        $stop2 = (new TripStop())->setCity($casa)->setStopOrder(2)->setArrivalTime(new \DateTimeImmutable('+2 days 14:30'))->setPriceFromPrevious('100.00');
        $trip->addStop($stop0)->addStop($stop1)->addStop($stop2);

        $this->em->persist($stop0);
        $this->em->persist($stop1);
        $this->em->persist($stop2);
        $this->em->persist($trip);

        // Booking 1: Agadir -> Marrakech (2 seats)
        $b1 = (new Booking())
            ->setTrip($trip)
            ->setPassenger($pass1)
            ->setFromStop($stop0)
            ->setToStop($stop1)
            ->setSeats(2)
            ->setPrice('240.00')
            ->setStatus(BookingStatus::CONFIRMED);

        // Booking 2: Marrakech -> Casablanca (3 seats - allowed because b1 finished at Marrakech)
        $b2 = (new Booking())
            ->setTrip($trip)
            ->setPassenger($pass2)
            ->setFromStop($stop1)
            ->setToStop($stop2)
            ->setSeats(3)
            ->setPrice('300.00')
            ->setStatus(BookingStatus::CONFIRMED);

        $this->em->persist($b1);
        $this->em->persist($b2);
        $this->em->flush();

        // 2. Query 1: Segment Overbooking
        // For each segment [s_from, s_to] of every trip, sum(seats) of confirmed bookings overlapping [s_from, s_to] must be <= seats_total
        $overbookedQuery = <<<SQL
            SELECT 
                t.id AS trip_id, 
                s_from.stop_order AS segment_start, 
                s_to.stop_order AS segment_end,
                t.seats_total, 
                SUM(b.seats) AS occupied_seats
            FROM trip t
            JOIN trip_stop s_from ON s_from.trip_id = t.id
            JOIN trip_stop s_to ON s_to.trip_id = t.id AND s_to.stop_order = s_from.stop_order + 1
            JOIN booking b ON b.trip_id = t.id AND b.status = 'CONFIRMED'
            JOIN trip_stop b_from ON b.from_stop_id = b_from.id
            JOIN trip_stop b_to ON b.to_stop_id = b_to.id
            WHERE b_from.stop_order <= s_from.stop_order AND b_to.stop_order >= s_to.stop_order
            GROUP BY t.id, s_from.stop_order, s_to.stop_order, t.seats_total
            HAVING occupied_seats > t.seats_total
        SQL;

        $overbookedSegments = $this->connection->fetchAllAssociative($overbookedQuery);
        self::assertSame([], $overbookedSegments, 'Aucun segment ne doit être surréservé.');

        // 3. Query 2: Orphan Bookings
        // A booking is orphan if:
        // - Its passenger does not exist in `user`
        // - Its trip does not exist in `trip`
        // - from_stop or to_stop does not exist in `trip_stop`
        // - from_stop or to_stop belongs to a different trip
        $orphanQuery = <<<SQL
            SELECT b.id AS orphan_booking_id, 'missing_passenger' AS reason
            FROM booking b
            LEFT JOIN `user` u ON b.passenger_id = u.id
            WHERE u.id IS NULL

            UNION ALL

            SELECT b.id AS orphan_booking_id, 'missing_trip' AS reason
            FROM booking b
            LEFT JOIN trip t ON b.trip_id = t.id
            WHERE t.id IS NULL

            UNION ALL

            SELECT b.id AS orphan_booking_id, 'missing_stops' AS reason
            FROM booking b
            LEFT JOIN trip_stop s_from ON b.from_stop_id = s_from.id
            LEFT JOIN trip_stop s_to ON b.to_stop_id = s_to.id
            WHERE s_from.id IS NULL OR s_to.id IS NULL

            UNION ALL

            SELECT b.id AS orphan_booking_id, 'stop_trip_mismatch' AS reason
            FROM booking b
            JOIN trip_stop s_from ON b.from_stop_id = s_from.id
            JOIN trip_stop s_to ON b.to_stop_id = s_to.id
            WHERE s_from.trip_id != b.trip_id OR s_to.trip_id != b.trip_id
        SQL;

        $orphanBookings = $this->connection->fetchAllAssociative($orphanQuery);
        self::assertSame([], $orphanBookings, 'Aucune réservation orpheline ne doit exister dans la base de données.');
    }
}
