<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Booking;
use App\Entity\City;
use App\Entity\Review;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class TripAndBookingFixtures extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array
    {
        return [
            CityFixtures::class,
            UserAndVehicleFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        $now = new \DateTimeImmutable();

        $agadir = $this->getReference(CityFixtures::CITY_AGADIR, City::class);
        $marrakech = $this->getReference(CityFixtures::CITY_MARRAKECH, City::class);
        $essaouira = $this->getReference(CityFixtures::CITY_ESSAOUIRA, City::class);
        $casablanca = $this->getReference(CityFixtures::CITY_CASABLANCA, City::class);
        $rabat = $this->getReference(CityFixtures::CITY_RABAT, City::class);
        $tiznit = $this->getReference(CityFixtures::CITY_TIZNIT, City::class);
        $taroudant = $this->getReference(CityFixtures::CITY_TAROUDANT, City::class);
        $safi = $this->getReference(CityFixtures::CITY_SAFI, City::class);
        $elJadida = $this->getReference(CityFixtures::CITY_EL_JADIDA, City::class);
        $fes = $this->getReference(CityFixtures::CITY_FES, City::class);

        $driver1 = $this->getReference(UserAndVehicleFixtures::USER_DRIVER_1, User::class);
        $driver2 = $this->getReference(UserAndVehicleFixtures::USER_DRIVER_2, User::class);
        $driver3 = $this->getReference(UserAndVehicleFixtures::USER_DRIVER_3, User::class);
        $driver4 = $this->getReference(UserAndVehicleFixtures::USER_DRIVER_4, User::class);

        $passenger1 = $this->getReference(UserAndVehicleFixtures::USER_PASSENGER_1, User::class);
        $passenger2 = $this->getReference(UserAndVehicleFixtures::USER_PASSENGER_2, User::class);
        $passenger3 = $this->getReference(UserAndVehicleFixtures::USER_PASSENGER_3, User::class);

        $vehicle1 = $this->getReference(UserAndVehicleFixtures::VEHICLE_1, Vehicle::class); // 4 places
        $vehicle2 = $this->getReference(UserAndVehicleFixtures::VEHICLE_2, Vehicle::class); // 3 places
        $vehicle3 = $this->getReference(UserAndVehicleFixtures::VEHICLE_3, Vehicle::class); // 4 places
        $vehicle4 = $this->getReference(UserAndVehicleFixtures::VEHICLE_4, Vehicle::class); // 4 places
        $vehicle5 = $this->getReference(UserAndVehicleFixtures::VEHICLE_5, Vehicle::class); // 4 places

        // =====================================================================
        // TRAJET 1 (PUBLISHED, +2 jours) : Agadir -> Marrakech -> Casablanca
        // =====================================================================
        $trip1Day = $now->modify('+2 days');
        [$trip1, $t1Stops] = $this->createTrip(
            $manager,
            $driver1,
            $vehicle1,
            4,
            TripStatus::PUBLISHED,
            $now->modify('-2 days'),
            null,
            [
                ['city' => $agadir, 'arr' => null, 'dep' => $trip1Day->setTime(8, 0), 'price' => '0.00'],
                ['city' => $marrakech, 'arr' => $trip1Day->setTime(11, 0), 'dep' => $trip1Day->setTime(11, 15), 'price' => '120.00'],
                ['city' => $casablanca, 'arr' => $trip1Day->setTime(14, 0), 'dep' => null, 'price' => '100.00'],
            ]
        );

        // =====================================================================
        // TRAJET 2 (PUBLISHED, +3 jours) : Agadir -> Essaouira -> Safi -> El Jadida
        // =====================================================================
        $trip2Day = $now->modify('+3 days');
        [$trip2, $t2Stops] = $this->createTrip(
            $manager,
            $driver2,
            $vehicle3,
            4,
            TripStatus::PUBLISHED,
            $now->modify('-2 days'),
            null,
            [
                ['city' => $agadir, 'arr' => null, 'dep' => $trip2Day->setTime(7, 30), 'price' => '0.00'],
                ['city' => $essaouira, 'arr' => $trip2Day->setTime(10, 30), 'dep' => $trip2Day->setTime(10, 45), 'price' => '80.00'],
                ['city' => $safi, 'arr' => $trip2Day->setTime(12, 45), 'dep' => $trip2Day->setTime(13, 0), 'price' => '60.00'],
                ['city' => $elJadida, 'arr' => $trip2Day->setTime(15, 0), 'dep' => null, 'price' => '70.00'],
            ]
        );

        // =====================================================================
        // TRAJET 3 (PUBLISHED, +4 jours) : Casablanca -> Rabat -> Fès
        // =====================================================================
        $trip3Day = $now->modify('+4 days');
        [$trip3, $t3Stops] = $this->createTrip(
            $manager,
            $driver3,
            $vehicle4,
            3,
            TripStatus::PUBLISHED,
            $now->modify('-1 day'),
            null,
            [
                ['city' => $casablanca, 'arr' => null, 'dep' => $trip3Day->setTime(9, 0), 'price' => '0.00'],
                ['city' => $rabat, 'arr' => $trip3Day->setTime(10, 15), 'dep' => $trip3Day->setTime(10, 30), 'price' => '50.00'],
                ['city' => $fes, 'arr' => $trip3Day->setTime(13, 0), 'dep' => null, 'price' => '110.00'],
            ]
        );

        // =====================================================================
        // TRAJET 4 (PUBLISHED, +5 jours) : Tiznit -> Agadir -> Taroudant
        // =====================================================================
        $trip4Day = $now->modify('+5 days');
        [$trip4, $t4Stops] = $this->createTrip(
            $manager,
            $driver4,
            $vehicle5,
            4,
            TripStatus::PUBLISHED,
            $now->modify('-1 day'),
            null,
            [
                ['city' => $tiznit, 'arr' => null, 'dep' => $trip4Day->setTime(8, 30), 'price' => '0.00'],
                ['city' => $agadir, 'arr' => $trip4Day->setTime(10, 0), 'dep' => $trip4Day->setTime(10, 15), 'price' => '45.00'],
                ['city' => $taroudant, 'arr' => $trip4Day->setTime(11, 30), 'dep' => null, 'price' => '40.00'],
            ]
        );

        // =====================================================================
        // TRAJET 5 (PUBLISHED, +6 jours) : Marrakech -> Casablanca -> Rabat
        // =====================================================================
        $trip5Day = $now->modify('+6 days');
        [$trip5, $t5Stops] = $this->createTrip(
            $manager,
            $driver1,
            $vehicle2,
            3,
            TripStatus::PUBLISHED,
            $now->modify('-12 hours'),
            null,
            [
                ['city' => $marrakech, 'arr' => null, 'dep' => $trip5Day->setTime(14, 0), 'price' => '0.00'],
                ['city' => $casablanca, 'arr' => $trip5Day->setTime(16, 45), 'dep' => $trip5Day->setTime(17, 0), 'price' => '100.00'],
                ['city' => $rabat, 'arr' => $trip5Day->setTime(18, 15), 'dep' => null, 'price' => '50.00'],
            ]
        );

        // =====================================================================
        // TRAJET 6 (PUBLISHED, +7 jours) : Fès -> Rabat -> Casablanca -> El Jadida
        // =====================================================================
        $trip6Day = $now->modify('+7 days');
        [$trip6, $t6Stops] = $this->createTrip(
            $manager,
            $driver2,
            $vehicle3,
            4,
            TripStatus::PUBLISHED,
            $now->modify('-6 hours'),
            null,
            [
                ['city' => $fes, 'arr' => null, 'dep' => $trip6Day->setTime(8, 0), 'price' => '0.00'],
                ['city' => $rabat, 'arr' => $trip6Day->setTime(10, 30), 'dep' => $trip6Day->setTime(10, 45), 'price' => '110.00'],
                ['city' => $casablanca, 'arr' => $trip6Day->setTime(12, 0), 'dep' => $trip6Day->setTime(12, 15), 'price' => '50.00'],
                ['city' => $elJadida, 'arr' => $trip6Day->setTime(13, 30), 'dep' => null, 'price' => '60.00'],
            ]
        );

        // =====================================================================
        // TRAJET 7 (CANCELLED, +1 jour) : Essaouira -> Marrakech
        // =====================================================================
        $trip7Day = $now->modify('+1 day');
        [$trip7, $t7Stops] = $this->createTrip(
            $manager,
            $driver3,
            $vehicle4,
            3,
            TripStatus::CANCELLED,
            $now->modify('-3 days'),
            $now->modify('-2 hours'),
            [
                ['city' => $essaouira, 'arr' => null, 'dep' => $trip7Day->setTime(15, 0), 'price' => '0.00'],
                ['city' => $marrakech, 'arr' => $trip7Day->setTime(17, 45), 'dep' => null, 'price' => '90.00'],
            ]
        );

        // =====================================================================
        // TRAJET 8 (COMPLETED, -3 jours) : Agadir -> Marrakech -> Casablanca
        // =====================================================================
        $trip8Day = $now->modify('-3 days');
        [$trip8, $t8Stops] = $this->createTrip(
            $manager,
            $driver4,
            $vehicle5,
            4,
            TripStatus::COMPLETED,
            $now->modify('-7 days'),
            null,
            [
                ['city' => $agadir, 'arr' => null, 'dep' => $trip8Day->setTime(9, 0), 'price' => '0.00'],
                ['city' => $marrakech, 'arr' => $trip8Day->setTime(12, 0), 'dep' => $trip8Day->setTime(12, 15), 'price' => '120.00'],
                ['city' => $casablanca, 'arr' => $trip8Day->setTime(15, 0), 'dep' => null, 'price' => '100.00'],
            ]
        );

        // =====================================================================
        // 12 RÉSERVATIONS CONFIRMED + 1 CANCELLED_BY_PASSENGER + 1 CANCELLED_BY_DRIVER
        // =====================================================================

        // --- Sur Trajet 1 (seatsTotal = 4) ---
        // 1. Trajet complet Agadir -> Casablanca (1 place, chevauche #2 et #3)
        $this->createBooking($manager, $trip1, $passenger1, $t1Stops[0], $t1Stops[2], 1, '220.00', BookingStatus::CONFIRMED, null, $now->modify('-1 day'));
        // 2. Portion Agadir -> Marrakech (2 places, adjacente à #3)
        $this->createBooking($manager, $trip1, $passenger2, $t1Stops[0], $t1Stops[1], 2, '240.00', BookingStatus::CONFIRMED, null, $now->modify('-20 hours'));
        // 3. Portion Marrakech -> Casablanca (2 places revendues après descente à Marrakech !)
        $this->createBooking($manager, $trip1, $passenger3, $t1Stops[1], $t1Stops[2], 2, '200.00', BookingStatus::CONFIRMED, null, $now->modify('-18 hours'));
        // [Annulée passager] Agadir -> Marrakech (1 place annulée par Omar)
        $this->createBooking($manager, $trip1, $driver3, $t1Stops[0], $t1Stops[1], 1, '120.00', BookingStatus::CANCELLED_BY_PASSENGER, '120.00', $now->modify('-30 hours'), $now->modify('-12 hours'));

        // --- Sur Trajet 2 (seatsTotal = 4) ---
        // 4. Portion Agadir -> Safi (2 places, chevauche #5 sur Essaouira -> Safi)
        $this->createBooking($manager, $trip2, $passenger1, $t2Stops[0], $t2Stops[2], 2, '280.00', BookingStatus::CONFIRMED, null, $now->modify('-1 day'));
        // 5. Portion Essaouira -> El Jadida (1 place, chevauche #4 sur Essaouira -> Safi)
        $this->createBooking($manager, $trip2, $passenger2, $t2Stops[1], $t2Stops[3], 1, '130.00', BookingStatus::CONFIRMED, null, $now->modify('-16 hours'));
        // 6. Segment Agadir -> Essaouira (1 place) -> occupation [0,1]=3/4, [1,2]=3/4, [2,3]=1/4
        $this->createBooking($manager, $trip2, $passenger3, $t2Stops[0], $t2Stops[1], 1, '80.00', BookingStatus::CONFIRMED, null, $now->modify('-10 hours'));

        // --- Sur Trajet 3 (seatsTotal = 3) ---
        // 7. Casablanca -> Fès (2 places)
        $this->createBooking($manager, $trip3, $passenger1, $t3Stops[0], $t3Stops[2], 2, '320.00', BookingStatus::CONFIRMED, null, $now->modify('-15 hours'));
        // 8. Rabat -> Fès (1 place) -> occupation [0,1]=2/3, [1,2]=3/3 (complet sur Rabat->Fès)
        $this->createBooking($manager, $trip3, $passenger2, $t3Stops[1], $t3Stops[2], 1, '110.00', BookingStatus::CONFIRMED, null, $now->modify('-8 hours'));

        // --- Sur Trajet 4 (seatsTotal = 4) ---
        // 9. Tiznit -> Agadir (2 places)
        $this->createBooking($manager, $trip4, $passenger3, $t4Stops[0], $t4Stops[1], 2, '90.00', BookingStatus::CONFIRMED, null, $now->modify('-12 hours'));

        // --- Sur Trajet 5 (seatsTotal = 3) ---
        // 10. Marrakech -> Casablanca (2 places)
        $this->createBooking($manager, $trip5, $passenger2, $t5Stops[0], $t5Stops[1], 2, '200.00', BookingStatus::CONFIRMED, null, $now->modify('-6 hours'));

        // --- Sur Trajet 6 (seatsTotal = 4) ---
        // 11. Fès -> Casablanca (1 place)
        $this->createBooking($manager, $trip6, $passenger3, $t6Stops[0], $t6Stops[2], 1, '160.00', BookingStatus::CONFIRMED, null, $now->modify('-4 hours'));

        // --- Sur Trajet 7 (CANCELLED, seatsTotal = 3) ---
        // [Annulée conducteur] Essaouira -> Marrakech (2 places remboursées intégralement)
        $this->createBooking($manager, $trip7, $passenger1, $t7Stops[0], $t7Stops[1], 2, '180.00', BookingStatus::CANCELLED_BY_DRIVER, '180.00', $now->modify('-2 days'), $now->modify('-2 hours'));

        // --- Sur Trajet 8 (COMPLETED, -3 jours, seatsTotal = 4) ---
        // 12. Agadir -> Casablanca (2 places, terminée)
        $completedBooking = $this->createBooking($manager, $trip8, $passenger1, $t8Stops[0], $t8Stops[2], 2, '440.00', BookingStatus::CONFIRMED, null, $now->modify('-5 days'));

        // =====================================================================
        // AVIS SUR LE TRAJET TERMINÉ (TRAJET 8)
        // =====================================================================
        $reviewPassengerToDriver = (new Review())
            ->setBooking($completedBooking)
            ->setAuthor($passenger1)
            ->setTarget($driver4)
            ->setRating(5)
            ->setComment('Excellent trajet entre Agadir et Casablanca ! Salma est très ponctuelle et prudente sur l\'autoroute.')
            ->setCreatedAt($now->modify('-2 days'));
        $manager->persist($reviewPassengerToDriver);

        $reviewDriverToPassenger = (new Review())
            ->setBooking($completedBooking)
            ->setAuthor($driver4)
            ->setTarget($passenger1)
            ->setRating(5)
            ->setComment('Mehdi est un passager très agréable et ponctuel au point de rendez-vous à Agadir.')
            ->setCreatedAt($now->modify('-2 days'));
        $manager->persist($reviewDriverToPassenger);

        $manager->flush();
    }

    /**
     * @param list<array{city: City, arr: ?\DateTimeImmutable, dep: ?\DateTimeImmutable, price: string}> $stopsData
     * @return array{0: Trip, 1: list<TripStop>}
     */
    private function createTrip(
        ObjectManager $manager,
        User $driver,
        Vehicle $vehicle,
        int $seatsTotal,
        TripStatus $status,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $cancelledAt,
        array $stopsData,
    ): array {
        $trip = (new Trip())
            ->setDriver($driver)
            ->setVehicle($vehicle)
            ->setSeatsTotal($seatsTotal)
            ->setStatus($status)
            ->setCreatedAt($createdAt)
            ->setCancelledAt($cancelledAt);

        $stops = [];
        foreach ($stopsData as $index => $stopData) {
            $stop = (new TripStop())
                ->setCity($stopData['city'])
                ->setStopOrder($index)
                ->setArrivalTime($stopData['arr'])
                ->setDepartureTime($stopData['dep'])
                ->setPriceFromPrevious($stopData['price']);

            $trip->addStop($stop);
            $manager->persist($stop);
            $stops[] = $stop;
        }

        $manager->persist($trip);

        return [$trip, $stops];
    }

    private function createBooking(
        ObjectManager $manager,
        Trip $trip,
        User $passenger,
        TripStop $fromStop,
        TripStop $toStop,
        int $seats,
        string $price,
        BookingStatus $status,
        ?string $refundAmount,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $cancelledAt = null,
    ): Booking {
        $booking = (new Booking())
            ->setTrip($trip)
            ->setPassenger($passenger)
            ->setFromStop($fromStop)
            ->setToStop($toStop)
            ->setSeats($seats)
            ->setPrice($price)
            ->setStatus($status)
            ->setRefundAmount($refundAmount)
            ->setCreatedAt($createdAt)
            ->setCancelledAt($cancelledAt);

        $manager->persist($booking);

        return $booking;
    }
}
