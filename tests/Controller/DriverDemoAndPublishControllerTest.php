<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\DataFixtures\CityFixtures;
use App\DataFixtures\DriverDemoFixtures;
use App\DataFixtures\TripAndBookingFixtures;
use App\DataFixtures\UserAndVehicleFixtures;
use App\Entity\Booking;
use App\Entity\City;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Loader;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class DriverDemoAndPublishControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->followRedirects(false);

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

    public function testMyTripsEmptyStateDisplaysPublishAndAddVehicleButtons(): void
    {
        $purePassenger = (new User())
            ->setEmail('empty.driver@test.ma')
            ->setFirstName('Nadia')
            ->setLastName('Berrada')
            ->setPhone('+212600110011')
            ->setPassword('hashed');
        $this->em->persist($purePassenger);
        $this->em->flush();

        $this->client->loginUser($purePassenger);
        $crawler = $this->client->request('GET', '/mes-trajets');
        self::assertResponseIsSuccessful();

        self::assertStringContainsString('Vous n\'avez pas encore publié de trajet', $crawler->text());
        self::assertGreaterThan(0, $crawler->filter('a[href="/trajets/nouveau"]')->count());
        self::assertGreaterThan(0, $crawler->filter('a[href="/mes-vehicules"]')->count());
        self::assertStringContainsString('0.00 MAD', $crawler->text());
    }

    public function testMyTripsWithDataShowsExactDbStatsAndIsolatesOtherDrivers(): void
    {
        $agadir = (new City())->setName('Agadir')->setLatitude('30.427755')->setLongitude('-9.598107');
        $marrakech = (new City())->setName('Marrakech')->setLatitude('31.629472')->setLongitude('-7.981084');
        $this->em->persist($agadir);
        $this->em->persist($marrakech);

        $driver = (new User())
            ->setEmail('driver.stats@test.ma')
            ->setFirstName('Mehdi')
            ->setLastName('Tazi')
            ->setPhone('+212600220011')
            ->setPassword('hashed');
        $otherDriver = (new User())
            ->setEmail('other.secret@test.ma')
            ->setFirstName('SecretDriver')
            ->setLastName('HiddenName')
            ->setPhone('+212699999999')
            ->setPassword('hashed');
        $passenger = (new User())
            ->setEmail('pass.stats@test.ma')
            ->setFirstName('Salma')
            ->setLastName('Chraibi')
            ->setPhone('+212600330011')
            ->setPassword('hashed');

        $this->em->persist($driver);
        $this->em->persist($otherDriver);
        $this->em->persist($passenger);

        $vehicle = (new Vehicle())->setOwner($driver)->setBrand('Hyundai')->setModel('Tucson')->setColor('Gris')->setSeats(4);
        $otherVehicle = (new Vehicle())->setOwner($otherDriver)->setBrand('SecretBrand')->setModel('SecretModel')->setColor('Noir')->setSeats(4);
        $this->em->persist($vehicle);
        $this->em->persist($otherVehicle);

        // Upcoming PUBLISHED trip for driver (+2d)
        $trip1 = (new Trip())->setDriver($driver)->setVehicle($vehicle)->setSeatsTotal(3)->setStatus(TripStatus::PUBLISHED);
        $s1a = (new TripStop())->setCity($agadir)->setStopOrder(0)->setDepartureTime(new \DateTimeImmutable('+2 days 10:00'))->setPriceFromPrevious('0.00');
        $s1b = (new TripStop())->setCity($marrakech)->setStopOrder(1)->setArrivalTime(new \DateTimeImmutable('+2 days 13:00'))->setPriceFromPrevious('120.50');
        $trip1->addStop($s1a)->addStop($s1b);
        $this->em->persist($s1a);
        $this->em->persist($s1b);
        $this->em->persist($trip1);

        // Past COMPLETED trip for driver (-3d)
        $trip2 = (new Trip())->setDriver($driver)->setVehicle($vehicle)->setSeatsTotal(3)->setStatus(TripStatus::COMPLETED);
        $s2a = (new TripStop())->setCity($marrakech)->setStopOrder(0)->setDepartureTime(new \DateTimeImmutable('-3 days 09:00'))->setPriceFromPrevious('0.00');
        $s2b = (new TripStop())->setCity($agadir)->setStopOrder(1)->setArrivalTime(new \DateTimeImmutable('-3 days 12:00'))->setPriceFromPrevious('100.25');
        $trip2->addStop($s2a)->addStop($s2b);
        $this->em->persist($s2a);
        $this->em->persist($s2b);
        $this->em->persist($trip2);

        // Other driver's trip (must NOT appear in driver's HTML)
        $otherTrip = (new Trip())->setDriver($otherDriver)->setVehicle($otherVehicle)->setSeatsTotal(3)->setStatus(TripStatus::PUBLISHED);
        $os1 = (new TripStop())->setCity($agadir)->setStopOrder(0)->setDepartureTime(new \DateTimeImmutable('+1 day 08:00'))->setPriceFromPrevious('0.00');
        $os2 = (new TripStop())->setCity($marrakech)->setStopOrder(1)->setArrivalTime(new \DateTimeImmutable('+1 day 11:00'))->setPriceFromPrevious('90.00');
        $otherTrip->addStop($os1)->addStop($os2);
        $this->em->persist($os1);
        $this->em->persist($os2);
        $this->em->persist($otherTrip);

        // Confirmed bookings: 2 seats * 120.50 = 241.00, 1 seat * 100.25 = 100.25 => total 3 seats, 341.25 MAD
        $b1 = (new Booking())->setTrip($trip1)->setPassenger($passenger)->setFromStop($s1a)->setToStop($s1b)->setSeats(2)->setPrice('241.00')->setStatus(BookingStatus::CONFIRMED);
        $b2 = (new Booking())->setTrip($trip2)->setPassenger($passenger)->setFromStop($s2a)->setToStop($s2b)->setSeats(1)->setPrice('100.25')->setStatus(BookingStatus::CONFIRMED);
        $bCancelled = (new Booking())->setTrip($trip1)->setPassenger($passenger)->setFromStop($s1a)->setToStop($s1b)->setSeats(1)->setPrice('120.50')->setStatus(BookingStatus::CANCELLED_BY_PASSENGER);
        $this->em->persist($b1);
        $this->em->persist($b2);
        $this->em->persist($bCancelled);
        $this->em->flush();
        $this->em->clear();

        $driver = $this->em->find(User::class, $driver->getId());
        self::assertNotNull($driver);

        $this->client->loginUser($driver);
        $crawler = $this->client->request('GET', '/mes-trajets');
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        $text = $crawler->text();

        // Chiffres exacts en base (bcmath)
        self::assertStringContainsString('341.25 MAD', $text);
        self::assertStringContainsString('Prochain départ', $text);
        self::assertStringContainsString('Agadir → Marrakech', $text);
        self::assertStringContainsString('Salma Chraibi', $text);

        // Aucune fuite de données d'un autre utilisateur
        self::assertStringNotContainsString('SecretDriver', $html);
        self::assertStringNotContainsString('SecretBrand', $html);
        self::assertStringNotContainsString('other.secret@test.ma', $html);
        self::assertStringNotContainsString('+212699999999', $html);
    }

    public function testDualRoleUserMehdiSeesBothDriverTripsAndPassengerBookingsFromFixtures(): void
    {
        /** @var \Symfony\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader $loader */
        $loader = static::getContainer()->get('doctrine.fixtures.loader');

        $executor = new ORMExecutor($this->em, new ORMPurger($this->em));
        $executor->execute($loader->getFixtures());

        $mehdi = $this->em->getRepository(User::class)->findOneBy(['email' => 'mehdi.tazi@covoiturage.ma']);
        self::assertNotNull($mehdi);

        $this->client->loginUser($mehdi);

        // 1. Espace conducteur (/mes-trajets) : 2 trajets publiés + 1 terminé + Hyundai Tucson
        $driverCrawler = $this->client->request('GET', '/mes-trajets');
        self::assertResponseIsSuccessful();
        $driverText = $driverCrawler->text();
        self::assertStringContainsString('Hyundai Tucson', $driverText);
        self::assertStringContainsString('Prochain départ', $driverText);
        // 120 + 120 + 100 + 80 = 420.00 MAD confirmés sur les trajets de Mehdi
        self::assertStringContainsString('420.00 MAD', $driverText);

        // 2. Espace passager (/mes-reservations) : ses propres réservations passager sont aussi visibles
        $passengerCrawler = $this->client->request('GET', '/mes-reservations');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Youssef Benali', $passengerCrawler->text());
    }

    public function testNewTripWithoutVehicleRedirectsBackAutomaticallyAfterQuickVehicleCreation(): void
    {
        $user = (new User())
            ->setEmail('novehicle.pub@test.ma')
            ->setFirstName('Karim')
            ->setLastName('Alami')
            ->setPhone('+212600440011')
            ->setPassword('hashed');
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/trajets/nouveau');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Aucun véhicule enregistré', $crawler->text());
        self::assertGreaterThan(0, $crawler->filter('a[href="/mes-vehicules?redirect=trip_new"]')->count());

        // Validation 1..8 places sur le mini-formulaire : 9 places refusé
        $badForm = $crawler->selectButton('Enregistrer et publier un trajet')->form([
            'brand' => 'Dacia',
            'model' => 'Lodgy',
            'color' => 'Blanc',
            'seats' => '9',
        ]);
        $this->client->submit($badForm);
        self::assertResponseRedirects('/trajets/nouveau');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');

        // Création valide -> retour automatique sur /trajets/nouveau avec le formulaire de trajet affiché
        $crawler = $this->client->request('GET', '/trajets/nouveau');
        $goodForm = $crawler->selectButton('Enregistrer et publier un trajet')->form([
            'brand' => 'Dacia',
            'model' => 'Duster',
            'color' => 'Gris',
            'seats' => '4',
        ]);
        $this->client->submit($goodForm);
        self::assertResponseRedirects('/trajets/nouveau');

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="vehicle_id"]');
        self::assertStringContainsString('Dacia Duster', $crawler->text());
    }

    public function testNewTripServerRevalidatesVehicleOwnershipAndInputs(): void
    {
        $agadir = (new City())->setName('Agadir')->setLatitude('30.427755')->setLongitude('-9.598107');
        $marrakech = (new City())->setName('Marrakech')->setLatitude('31.629472')->setLongitude('-7.981084');
        $this->em->persist($agadir);
        $this->em->persist($marrakech);

        $driver = (new User())->setEmail('drv.reval@test.ma')->setFirstName('A')->setLastName('B')->setPhone('+212600550011')->setPassword('h');
        $other = (new User())->setEmail('oth.reval@test.ma')->setFirstName('C')->setLastName('D')->setPhone('+212600550022')->setPassword('h');
        $this->em->persist($driver);
        $this->em->persist($other);

        $myVehicle = (new Vehicle())->setOwner($driver)->setBrand('Renault')->setModel('Clio')->setColor('Noir')->setSeats(4);
        $otherVehicle = (new Vehicle())->setOwner($other)->setBrand('Peugeot')->setModel('208')->setColor('Bleu')->setSeats(4);
        $this->em->persist($myVehicle);
        $this->em->persist($otherVehicle);
        $this->em->flush();

        $this->client->loginUser($driver);
        $crawler = $this->client->request('GET', '/trajets/nouveau');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        // 1. Tentative d'utiliser le véhicule d'un tiers -> 403 Forbidden
        $this->client->request('POST', '/trajets/nouveau', [
            '_token' => $token,
            'vehicle_id' => (string) $otherVehicle->getId(),
            'seats_total' => 2,
            'stops' => [
                0 => ['city_id' => (string) $agadir->getId(), 'departure_time' => '+2 days 08:00', 'price_from_previous' => '0.00'],
                1 => ['city_id' => (string) $marrakech->getId(), 'arrival_time' => '+2 days 11:00', 'price_from_previous' => '100.00'],
            ],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // 2. Date malformée -> redirection avec flash d'erreur (pas de 500)
        $this->client->request('POST', '/trajets/nouveau', [
            '_token' => $token,
            'vehicle_id' => (string) $myVehicle->getId(),
            'seats_total' => 2,
            'stops' => [
                0 => ['city_id' => (string) $agadir->getId(), 'departure_time' => 'not-a-date', 'price_from_previous' => '0.00'],
                1 => ['city_id' => (string) $marrakech->getId(), 'arrival_time' => '+2 days 11:00', 'price_from_previous' => '100.00'],
            ],
        ]);
        self::assertResponseRedirects('/trajets/nouveau');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');
    }
}
