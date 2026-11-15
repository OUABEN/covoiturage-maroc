<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Booking;
use App\Entity\City;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class DriverSpaceControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);

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
     * @return array{driver: User, passenger: User, intruder: User, agadir: City, marrakech: City, casa: City, vehicle: Vehicle}
     */
    private function createScenarioData(): array
    {
        $suffix = bin2hex(random_bytes(4));

        $agadir = (new City())->setName('DrvAgadir_'.$suffix)->setLatitude('30.427755')->setLongitude('-9.598107');
        $marrakech = (new City())->setName('DrvMarrakech_'.$suffix)->setLatitude('31.629472')->setLongitude('-7.981084');
        $casa = (new City())->setName('DrvCasa_'.$suffix)->setLatitude('33.573110')->setLongitude('-7.589843');
        $this->em->persist($agadir);
        $this->em->persist($marrakech);
        $this->em->persist($casa);

        $driver = (new User())
            ->setEmail('driver.space.'.$suffix.'@covoit.ma')
            ->setFirstName('Youssef')
            ->setLastName('Conducteur '.$suffix)
            ->setPhone('+212600889900');
        $driver->setPassword($this->passwordHasher->hashPassword($driver, 'secret1234'));

        $passenger = (new User())
            ->setEmail('passenger.space.'.$suffix.'@covoit.ma')
            ->setFirstName('Amina')
            ->setLastName('Passagere '.$suffix)
            ->setPhone('+212600889901');
        $passenger->setPassword($this->passwordHasher->hashPassword($passenger, 'secret1234'));

        $intruder = (new User())
            ->setEmail('intruder.space.'.$suffix.'@covoit.ma')
            ->setFirstName('Omar')
            ->setLastName('Tiers '.$suffix)
            ->setPhone('+212600889902');
        $intruder->setPassword($this->passwordHasher->hashPassword($intruder, 'secret1234'));

        $vehicle = (new Vehicle())
            ->setOwner($driver)
            ->setBrand('Renault')
            ->setModel('Kangoo')
            ->setColor('Blanc')
            ->setSeats(4);
        $this->em->persist($vehicle);

        $this->em->persist($driver);
        $this->em->persist($passenger);
        $this->em->persist($intruder);
        $this->em->flush();

        return [
            'driver' => $driver,
            'passenger' => $passenger,
            'intruder' => $intruder,
            'agadir' => $agadir,
            'marrakech' => $marrakech,
            'casa' => $casa,
            'vehicle' => $vehicle,
        ];
    }

    public function testVehicleCreationAndListing(): void
    {
        $d = $this->createScenarioData();
        $this->client->loginUser($d['driver']);

        $crawler = $this->client->request('GET', '/mes-vehicules');
        self::assertResponseIsSuccessful();

        $vehicleForm = $crawler->selectButton('Ajouter le véhicule')->form([
            'brand' => 'Dacia',
            'model' => 'Sandero',
            'color' => 'Noir',
            'seats' => '4',
        ]);
        $this->client->submit($vehicleForm);
        self::assertResponseRedirects('/mes-vehicules');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Dacia Sandero', $crawler->text());

        $sandero = $this->em->getRepository(Vehicle::class)->findOneBy(['model' => 'Sandero']);
        self::assertNotNull($sandero);
    }

    public function testTripPublicationRejectsInvalidData(): void
    {
        $d = $this->createScenarioData();
        $this->client->loginUser($d['driver']);

        $crawler = $this->client->request('GET', '/trajets/nouveau');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $dep0 = (new \DateTimeImmutable('+2 days 08:00'))->format('Y-m-d\TH:i');
        $arr1 = (new \DateTimeImmutable('+2 days 11:00'))->format('Y-m-d\TH:i');

        // Invalid: seatsTotal > vehicle.seats (6 > 4)
        $this->client->request('POST', '/trajets/nouveau', [
            '_token' => $token,
            'vehicle_id' => (string) $d['vehicle']->getId(),
            'seats_total' => 6,
            'stops' => [
                0 => ['city_id' => (string) $d['agadir']->getId(), 'departure_time' => $dep0, 'price_from_previous' => '0.00'],
                1 => ['city_id' => (string) $d['marrakech']->getId(), 'arrival_time' => $arr1, 'price_from_previous' => '120.00'],
            ],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-danger');

        // Invalid: only 1 stop
        $this->client->request('POST', '/trajets/nouveau', [
            '_token' => $token,
            'vehicle_id' => (string) $d['vehicle']->getId(),
            'seats_total' => 3,
            'stops' => [
                0 => ['city_id' => (string) $d['agadir']->getId(), 'departure_time' => $dep0, 'price_from_previous' => '0.00'],
            ],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-danger');

        // Invalid: arrival before departure
        $badArr1 = (new \DateTimeImmutable('+2 days 07:00'))->format('Y-m-d\TH:i');
        $this->client->request('POST', '/trajets/nouveau', [
            '_token' => $token,
            'vehicle_id' => (string) $d['vehicle']->getId(),
            'seats_total' => 3,
            'stops' => [
                0 => ['city_id' => (string) $d['agadir']->getId(), 'departure_time' => $dep0, 'price_from_previous' => '0.00'],
                1 => ['city_id' => (string) $d['marrakech']->getId(), 'arrival_time' => $badArr1, 'price_from_previous' => '120.00'],
            ],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-danger');
    }

    public function testTripPublicationNominalThreeStops(): void
    {
        $d = $this->createScenarioData();
        $this->client->loginUser($d['driver']);

        $crawler = $this->client->request('GET', '/trajets/nouveau');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $dep0 = (new \DateTimeImmutable('+2 days 08:00'))->format('Y-m-d\TH:i');
        $arr1 = (new \DateTimeImmutable('+2 days 11:00'))->format('Y-m-d\TH:i');
        $dep1 = (new \DateTimeImmutable('+2 days 11:15'))->format('Y-m-d\TH:i');
        $arr2 = (new \DateTimeImmutable('+2 days 14:00'))->format('Y-m-d\TH:i');

        $this->client->request('POST', '/trajets/nouveau', [
            '_token' => $token,
            'vehicle_id' => (string) $d['vehicle']->getId(),
            'seats_total' => 3,
            'stops' => [
                0 => ['city_id' => (string) $d['agadir']->getId(), 'departure_time' => $dep0, 'price_from_previous' => '0.00'],
                1 => ['city_id' => (string) $d['marrakech']->getId(), 'arrival_time' => $arr1, 'departure_time' => $dep1, 'price_from_previous' => '120.00'],
                2 => ['city_id' => (string) $d['casa']->getId(), 'arrival_time' => $arr2, 'departure_time' => '', 'price_from_previous' => '100.00'],
            ],
        ]);
        self::assertResponseRedirects('/mes-trajets');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Votre trajet a été publié avec succès', $crawler->text());

        $trip = $this->em->getRepository(Trip::class)->findOneBy(['driver' => $d['driver']]);
        self::assertNotNull($trip);
        self::assertCount(3, $trip->getStops());
    }

    public function testIdorVehicleAndTripVotersDenyThirdParty(): void
    {
        $d = $this->createScenarioData();

        // Create a trip owned by driver
        $trip = (new Trip())
            ->setDriver($d['driver'])
            ->setVehicle($d['vehicle'])
            ->setSeatsTotal(3)
            ->setStatus(TripStatus::PUBLISHED);
        $stop1 = (new TripStop())->setCity($d['agadir'])->setStopOrder(0)->setDepartureTime(new \DateTimeImmutable('+2 days 08:00'))->setPriceFromPrevious('0.00');
        $stop2 = (new TripStop())->setCity($d['marrakech'])->setStopOrder(1)->setArrivalTime(new \DateTimeImmutable('+2 days 11:00'))->setPriceFromPrevious('120.00');
        $trip->addStop($stop1)->addStop($stop2);
        $this->em->persist($stop1);
        $this->em->persist($stop2);
        $this->em->persist($trip);
        $this->em->flush();

        // Intruder tries to modify/cancel driver's trip or vehicle -> 403 Forbidden
        $this->client->loginUser($d['intruder']);

        $this->client->request('POST', sprintf('/mes-vehicules/%d/supprimer', $d['vehicle']->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('POST', sprintf('/mes-trajets/%d/annuler', $trip->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('POST', sprintf('/mes-trajets/arrets/%d/horaire', $stop1->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('POST', sprintf('/mes-trajets/%d/terminer', $trip->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testVehicleDeletionRefusedWhenAssignedToTrip(): void
    {
        $d = $this->createScenarioData();

        $trip = (new Trip())
            ->setDriver($d['driver'])
            ->setVehicle($d['vehicle'])
            ->setSeatsTotal(3)
            ->setStatus(TripStatus::PUBLISHED);
        $stop1 = (new TripStop())->setCity($d['agadir'])->setStopOrder(0)->setDepartureTime(new \DateTimeImmutable('+2 days 08:00'))->setPriceFromPrevious('0.00');
        $stop2 = (new TripStop())->setCity($d['marrakech'])->setStopOrder(1)->setArrivalTime(new \DateTimeImmutable('+2 days 11:00'))->setPriceFromPrevious('120.00');
        $trip->addStop($stop1)->addStop($stop2);
        $this->em->persist($stop1);
        $this->em->persist($stop2);
        $this->em->persist($trip);
        $this->em->flush();

        $this->client->loginUser($d['driver']);
        $crawler = $this->client->request('GET', '/mes-vehicules');
        $token = $crawler->filter(sprintf('form[action$="/mes-vehicules/%d/supprimer"] input[name="_token"]', $d['vehicle']->getId()))->attr('value');

        $this->client->request('POST', sprintf('/mes-vehicules/%d/supprimer', $d['vehicle']->getId()), [
            '_token' => $token,
        ]);
        self::assertResponseRedirects('/mes-vehicules');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');

        // Vehicle still in DB
        $reloaded = $this->em->find(Vehicle::class, $d['vehicle']->getId());
        self::assertNotNull($reloaded);
    }

    public function testMissingCsrfTokenRejectsDriverActions(): void
    {
        $d = $this->createScenarioData();

        $trip = (new Trip())
            ->setDriver($d['driver'])
            ->setVehicle($d['vehicle'])
            ->setSeatsTotal(3)
            ->setStatus(TripStatus::PUBLISHED);
        $stop1 = (new TripStop())->setCity($d['agadir'])->setStopOrder(0)->setDepartureTime(new \DateTimeImmutable('+2 days 08:00'))->setPriceFromPrevious('0.00');
        $stop2 = (new TripStop())->setCity($d['marrakech'])->setStopOrder(1)->setArrivalTime(new \DateTimeImmutable('+2 days 11:00'))->setPriceFromPrevious('120.00');
        $trip->addStop($stop1)->addStop($stop2);
        $this->em->persist($stop1);
        $this->em->persist($stop2);
        $this->em->persist($trip);
        $this->em->flush();

        $this->client->loginUser($d['driver']);

        // Missing CSRF on cancel trip
        $this->client->request('POST', sprintf('/mes-trajets/%d/annuler', $trip->getId()), [
            '_token' => 'invalid_csrf',
        ]);
        self::assertResponseRedirects('/mes-trajets');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');

        // Missing CSRF on complete trip
        $this->client->request('POST', sprintf('/mes-trajets/%d/terminer', $trip->getId()), [
            '_token' => 'invalid_csrf',
        ]);
        self::assertResponseRedirects('/mes-trajets');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');

        // Missing CSRF on delete vehicle
        $this->client->request('POST', sprintf('/mes-vehicules/%d/supprimer', $d['vehicle']->getId()), [
            '_token' => 'invalid_csrf',
        ]);
        self::assertResponseRedirects('/mes-vehicules');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');
    }

    public function testTripCompletionBeforeArrivalTimeIsRefused(): void
    {
        $d = $this->createScenarioData();

        // Trip departing and arriving in 2 days (future)
        $trip = (new Trip())
            ->setDriver($d['driver'])
            ->setVehicle($d['vehicle'])
            ->setSeatsTotal(3)
            ->setStatus(TripStatus::PUBLISHED);
        $stop1 = (new TripStop())->setCity($d['agadir'])->setStopOrder(0)->setDepartureTime(new \DateTimeImmutable('+2 days 08:00'))->setPriceFromPrevious('0.00');
        $stop2 = (new TripStop())->setCity($d['marrakech'])->setStopOrder(1)->setArrivalTime(new \DateTimeImmutable('+2 days 11:00'))->setPriceFromPrevious('120.00');
        $trip->addStop($stop1)->addStop($stop2);
        $this->em->persist($stop1);
        $this->em->persist($stop2);
        $this->em->persist($trip);
        $this->em->flush();

        $this->client->loginUser($d['driver']);
        $crawler = $this->client->request('GET', '/mes-trajets');
        $token = $crawler->filter(sprintf('form[action$="/mes-trajets/%d/terminer"] input[name="_token"]', $trip->getId()))->attr('value');

        $this->client->request('POST', sprintf('/mes-trajets/%d/terminer', $trip->getId()), [
            '_token' => $token,
        ]);
        self::assertResponseRedirects('/mes-trajets');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');
        self::assertSelectorTextContains('.alert-danger', 'Impossible de marquer un trajet comme terminé avant son heure d\'arrivée');

        $this->em->clear();
        $reloaded = $this->em->find(Trip::class, $trip->getId());
        self::assertSame(TripStatus::PUBLISHED, $reloaded?->getStatus());
    }

    public function testDriverCancelsTripRefundsAllConfirmedBookings100Percent(): void
    {
        $d = $this->createScenarioData();

        $trip = (new Trip())
            ->setDriver($d['driver'])
            ->setVehicle($d['vehicle'])
            ->setSeatsTotal(3)
            ->setStatus(TripStatus::PUBLISHED);
        $stop1 = (new TripStop())->setCity($d['agadir'])->setStopOrder(0)->setDepartureTime(new \DateTimeImmutable('+2 days 08:00'))->setPriceFromPrevious('0.00');
        $stop2 = (new TripStop())->setCity($d['marrakech'])->setStopOrder(1)->setArrivalTime(new \DateTimeImmutable('+2 days 11:00'))->setPriceFromPrevious('120.00');
        $trip->addStop($stop1)->addStop($stop2);
        $this->em->persist($stop1);
        $this->em->persist($stop2);
        $this->em->persist($trip);

        $booking = (new Booking())
            ->setTrip($trip)
            ->setPassenger($d['passenger'])
            ->setFromStop($stop1)
            ->setToStop($stop2)
            ->setSeats(1)
            ->setPrice('120.00')
            ->setStatus(BookingStatus::CONFIRMED);
        $this->em->persist($booking);
        $this->em->flush();

        $this->client->loginUser($d['driver']);
        $crawler = $this->client->request('GET', '/mes-trajets');
        $token = $crawler->filter(sprintf('form[action$="/mes-trajets/%d/annuler"] input[name="_token"]', $trip->getId()))->attr('value');

        $this->client->request('POST', sprintf('/mes-trajets/%d/annuler', $trip->getId()), [
            '_token' => $token,
        ]);
        self::assertResponseRedirects('/mes-trajets');

        $this->em->clear();
        $reloadedTrip = $this->em->find(Trip::class, $trip->getId());
        $reloadedBooking = $this->em->find(Booking::class, $booking->getId());
        self::assertSame(TripStatus::CANCELLED, $reloadedTrip?->getStatus());
        self::assertSame(BookingStatus::CANCELLED_BY_DRIVER, $reloadedBooking?->getStatus());
        self::assertSame('120.00', $reloadedBooking?->getRefundAmount());
    }
}
