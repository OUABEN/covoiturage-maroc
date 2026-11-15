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

/**
 * Fixtures additives de démonstration pour l'espace conducteur :
 * - Donne un véhicule + 2 trajets PUBLISHED à venir + 1 trajet COMPLETED avec avis à mehdi.tazi@covoiturage.ma
 *   (qui devient à la fois passager ET conducteur).
 * - Complète chaque conducteur existant (driver1..driver3) avec un trajet COMPLETED passé + réservation/avis
 *   pour garantir au moins 1 trajet à venir et 1 trajet passé par conducteur.
 */
class DriverDemoFixtures extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array
    {
        return [
            CityFixtures::class,
            UserAndVehicleFixtures::class,
            TripAndBookingFixtures::class,
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
        $fes = $this->getReference(CityFixtures::CITY_FES, City::class);

        $mehdi = $this->getReference(UserAndVehicleFixtures::USER_PASSENGER_1, User::class);
        $nadia = $this->getReference(UserAndVehicleFixtures::USER_PASSENGER_2, User::class);
        $hamza = $this->getReference(UserAndVehicleFixtures::USER_PASSENGER_3, User::class);

        $driver1 = $this->getReference(UserAndVehicleFixtures::USER_DRIVER_1, User::class);
        $driver2 = $this->getReference(UserAndVehicleFixtures::USER_DRIVER_2, User::class);
        $driver3 = $this->getReference(UserAndVehicleFixtures::USER_DRIVER_3, User::class);

        $vehicle1 = $this->getReference(UserAndVehicleFixtures::VEHICLE_1, Vehicle::class);
        $vehicle3 = $this->getReference(UserAndVehicleFixtures::VEHICLE_3, Vehicle::class);
        $vehicle4 = $this->getReference(UserAndVehicleFixtures::VEHICLE_4, Vehicle::class);

        // 1. Véhicule pour Mehdi Tazi (devient passager ET conducteur)
        $mehdiVehicle = (new Vehicle())
            ->setOwner($mehdi)
            ->setBrand('Hyundai')
            ->setModel('Tucson')
            ->setColor('Gris Titan')
            ->setSeats(4);
        $manager->persist($mehdiVehicle);

        // 2. Trajet PUBLISHED à venir #1 pour Mehdi : Agadir -> Marrakech (+2 jours)
        $mTrip1Day = $now->modify('+2 days');
        [$mTrip1, $m1Stops] = $this->createTrip(
            $manager,
            $mehdi,
            $mehdiVehicle,
            3,
            TripStatus::PUBLISHED,
            $now->modify('-1 day'),
            [
                ['city' => $agadir, 'arr' => null, 'dep' => $mTrip1Day->setTime(10, 0), 'price' => '0.00'],
                ['city' => $marrakech, 'arr' => $mTrip1Day->setTime(13, 0), 'dep' => null, 'price' => '120.00'],
            ]
        );
        $this->createBooking($manager, $mTrip1, $nadia, $m1Stops[0], $m1Stops[1], 1, '120.00', BookingStatus::CONFIRMED, $now->modify('-10 hours'));
        $this->createBooking($manager, $mTrip1, $hamza, $m1Stops[0], $m1Stops[1], 1, '120.00', BookingStatus::CONFIRMED, $now->modify('-6 hours'));

        // 3. Trajet PUBLISHED à venir #2 pour Mehdi : Marrakech -> Casablanca -> Rabat (+4 jours)
        $mTrip2Day = $now->modify('+4 days');
        [$mTrip2, $m2Stops] = $this->createTrip(
            $manager,
            $mehdi,
            $mehdiVehicle,
            3,
            TripStatus::PUBLISHED,
            $now->modify('-8 hours'),
            [
                ['city' => $marrakech, 'arr' => null, 'dep' => $mTrip2Day->setTime(15, 0), 'price' => '0.00'],
                ['city' => $casablanca, 'arr' => $mTrip2Day->setTime(17, 45), 'dep' => $mTrip2Day->setTime(18, 0), 'price' => '100.00'],
                ['city' => $rabat, 'arr' => $mTrip2Day->setTime(19, 15), 'dep' => null, 'price' => '50.00'],
            ]
        );
        $this->createBooking($manager, $mTrip2, $nadia, $m2Stops[0], $m2Stops[1], 1, '100.00', BookingStatus::CONFIRMED, $now->modify('-4 hours'));

        // 4. Trajet COMPLETED passé pour Mehdi : Agadir -> Essaouira (-5 jours) avec avis croisés
        $mTrip3Day = $now->modify('-5 days');
        [$mTrip3, $m3Stops] = $this->createTrip(
            $manager,
            $mehdi,
            $mehdiVehicle,
            3,
            TripStatus::COMPLETED,
            $now->modify('-9 days'),
            [
                ['city' => $agadir, 'arr' => null, 'dep' => $mTrip3Day->setTime(9, 0), 'price' => '0.00'],
                ['city' => $essaouira, 'arr' => $mTrip3Day->setTime(12, 0), 'dep' => null, 'price' => '80.00'],
            ]
        );
        $mCompletedBooking = $this->createBooking($manager, $mTrip3, $hamza, $m3Stops[0], $m3Stops[1], 1, '80.00', BookingStatus::CONFIRMED, $now->modify('-7 days'));
        $manager->persist(
            (new Review())
                ->setBooking($mCompletedBooking)
                ->setAuthor($hamza)
                ->setTarget($mehdi)
                ->setRating(5)
                ->setComment('Conduite fluide et très ponctuelle entre Agadir et Essaouira.')
                ->setCreatedAt($now->modify('-4 days'))
        );
        $manager->persist(
            (new Review())
                ->setBooking($mCompletedBooking)
                ->setAuthor($mehdi)
                ->setTarget($hamza)
                ->setRating(5)
                ->setComment('Passager ponctuel et agréable.')
                ->setCreatedAt($now->modify('-4 days'))
        );

        // 5. Trajets COMPLETED passés pour les conducteurs existants qui n'en avaient pas encore
        // (driver4 Salma possède déjà Trip 8 COMPLETED)
        $d1PastDay = $now->modify('-4 days');
        [$d1PastTrip, $d1PastStops] = $this->createTrip(
            $manager,
            $driver1,
            $vehicle1,
            4,
            TripStatus::COMPLETED,
            $now->modify('-8 days'),
            [
                ['city' => $casablanca, 'arr' => null, 'dep' => $d1PastDay->setTime(16, 0), 'price' => '0.00'],
                ['city' => $rabat, 'arr' => $d1PastDay->setTime(17, 15), 'dep' => null, 'price' => '50.00'],
            ]
        );
        $this->createBooking($manager, $d1PastTrip, $hamza, $d1PastStops[0], $d1PastStops[1], 1, '50.00', BookingStatus::CONFIRMED, $now->modify('-6 days'));

        $d2PastDay = $now->modify('-6 days');
        [$d2PastTrip, $d2PastStops] = $this->createTrip(
            $manager,
            $driver2,
            $vehicle3,
            4,
            TripStatus::COMPLETED,
            $now->modify('-10 days'),
            [
                ['city' => $rabat, 'arr' => null, 'dep' => $d2PastDay->setTime(8, 30), 'price' => '0.00'],
                ['city' => $fes, 'arr' => $d2PastDay->setTime(11, 0), 'dep' => null, 'price' => '110.00'],
            ]
        );
        $this->createBooking($manager, $d2PastTrip, $nadia, $d2PastStops[0], $d2PastStops[1], 1, '110.00', BookingStatus::CONFIRMED, $now->modify('-8 days'));

        $d3PastDay = $now->modify('-7 days');
        [$d3PastTrip, $d3PastStops] = $this->createTrip(
            $manager,
            $driver3,
            $vehicle4,
            3,
            TripStatus::COMPLETED,
            $now->modify('-11 days'),
            [
                ['city' => $marrakech, 'arr' => null, 'dep' => $d3PastDay->setTime(14, 0), 'price' => '0.00'],
                ['city' => $agadir, 'arr' => $d3PastDay->setTime(17, 0), 'dep' => null, 'price' => '120.00'],
            ]
        );
        $this->createBooking($manager, $d3PastTrip, $hamza, $d3PastStops[0], $d3PastStops[1], 1, '120.00', BookingStatus::CONFIRMED, $now->modify('-9 days'));

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
        array $stopsData,
    ): array {
        $trip = (new Trip())
            ->setDriver($driver)
            ->setVehicle($vehicle)
            ->setSeatsTotal($seatsTotal)
            ->setStatus($status)
            ->setCreatedAt($createdAt);

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
        \DateTimeImmutable $createdAt,
    ): Booking {
        $booking = (new Booking())
            ->setTrip($trip)
            ->setPassenger($passenger)
            ->setFromStop($fromStop)
            ->setToStop($toStop)
            ->setSeats($seats)
            ->setPrice($price)
            ->setStatus($status)
            ->setCreatedAt($createdAt);

        $manager->persist($booking);

        return $booking;
    }
}
