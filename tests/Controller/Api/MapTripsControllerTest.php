<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\City;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\TripStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class MapTripsControllerTest extends WebTestCase
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
     * @return array{driver: User, vehicle: Vehicle, agadir: City, marrakech: City, casablanca: City, trip: Trip}
     */
    private function createFixtures(): array
    {
        $driver = (new User())
            ->setEmail('driver.map@covoiturage.ma')
            ->setFirstName('Reda')
            ->setLastName('Tazi')
            ->setPhone('+212 6 62 11 22 33');
        $driver->setPassword($this->passwordHasher->hashPassword($driver, 'demo1234'));
        $this->em->persist($driver);

        $vehicle = (new Vehicle())
            ->setOwner($driver)
            ->setBrand('Peugeot')
            ->setModel('208')
            ->setSeats(3)
            ->setColor('Blanc');
        $this->em->persist($vehicle);

        $agadir = (new City())->setName('Agadir')->setLatitude('30.427755')->setLongitude('-9.598107');
        $marrakech = (new City())->setName('Marrakech')->setLatitude('31.629472')->setLongitude('-7.981084');
        $casablanca = (new City())->setName('Casablanca')->setLatitude('33.573110')->setLongitude('-7.589843');
        $this->em->persist($agadir);
        $this->em->persist($marrakech);
        $this->em->persist($casablanca);

        $now = new \DateTimeImmutable();
        $trip = (new Trip())
            ->setDriver($driver)
            ->setVehicle($vehicle)
            ->setSeatsTotal(3)
            ->setStatus(TripStatus::PUBLISHED)
            ->setCreatedAt($now);
        $this->em->persist($trip);

        $depTime = $now->modify('+2 days')->setTime(9, 0);

        $stop1 = (new TripStop())
            ->setTrip($trip)
            ->setCity($agadir)
            ->setStopOrder(1)
            ->setDepartureTime($depTime)
            ->setPriceFromPrevious('0.00');
        $this->em->persist($stop1);
        $trip->addStop($stop1);

        $stop2 = (new TripStop())
            ->setTrip($trip)
            ->setCity($marrakech)
            ->setStopOrder(2)
            ->setArrivalTime($depTime->modify('+3 hours'))
            ->setDepartureTime($depTime->modify('+3 hours 15 minutes'))
            ->setPriceFromPrevious('120.00');
        $this->em->persist($stop2);
        $trip->addStop($stop2);

        $stop3 = (new TripStop())
            ->setTrip($trip)
            ->setCity($casablanca)
            ->setStopOrder(3)
            ->setArrivalTime($depTime->modify('+6 hours'))
            ->setDepartureTime(null)
            ->setPriceFromPrevious('100.00');
        $this->em->persist($stop3);
        $trip->addStop($stop3);

        $this->em->flush();

        return [
            'driver' => $driver,
            'vehicle' => $vehicle,
            'agadir' => $agadir,
            'marrakech' => $marrakech,
            'casablanca' => $casablanca,
            'trip' => $trip,
        ];
    }

    public function testMapTripsReturns200WithResultsAndNoSensitiveData(): void
    {
        $fixtures = $this->createFixtures();
        $agadir = $fixtures['agadir'];
        $marrakech = $fixtures['marrakech'];

        $this->client->request('GET', sprintf('/api/map/trips?from=%d&to=%d', $agadir->getId(), $marrakech->getId()));
        self::assertResponseIsSuccessful();

        $rawResponse = (string) $this->client->getResponse()->getContent();
        $data = json_decode($rawResponse, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $data['count']);
        self::assertSame('Agadir', $data['from']['name']);
        self::assertSame('Marrakech', $data['to']['name']);
        self::assertCount(1, $data['trips']);

        $tripItem = $data['trips'][0];
        self::assertSame('Reda', $tripItem['driver']['firstName']);
        self::assertSame('T.', $tripItem['driver']['lastNameInitial']);
        self::assertSame('120.00', $tripItem['portionPrice']);
        self::assertSame(3, $tripItem['availableSeats']);
        self::assertNotEmpty($tripItem['detailUrl']);

        // Strict security assertion: no email, no phone, no password hash
        self::assertStringNotContainsString('@', $rawResponse);
        self::assertStringNotContainsString('62 11 22 33', $rawResponse);
        self::assertStringNotContainsString('password', $rawResponse);
    }

    public function testMapTripsReturns200WithOnlyFromParameter(): void
    {
        $fixtures = $this->createFixtures();
        $agadir = $fixtures['agadir'];

        $this->client->request('GET', sprintf('/api/map/trips?from=%d', $agadir->getId()));
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $data['count']);
        self::assertNull($data['to']);
        self::assertNotEmpty($data['trips']);
        self::assertCount(2, $data['trips'][0]['destinations']);
        self::assertSame('Marrakech', $data['trips'][0]['destinations'][0]['cityName']);
        self::assertSame('Casablanca', $data['trips'][0]['destinations'][1]['cityName']);
    }

    public function testMapTripsReturns200EmptyWhenNoTrips(): void
    {
        $fixtures = $this->createFixtures();
        $casablanca = $fixtures['casablanca'];
        $agadir = $fixtures['agadir'];

        // Casablanca to Agadir has no trip in our fixture (trip is Agadir -> Casablanca)
        $this->client->request('GET', sprintf('/api/map/trips?from=%d&to=%d', $casablanca->getId(), $agadir->getId()));
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $data['count']);
        self::assertSame([], $data['trips']);
    }

    public function testMapTripsReturns400OnInvalidParameters(): void
    {
        // 1. Missing "from"
        $this->client->request('GET', '/api/map/trips');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        // 2. Non-numeric "from"
        $this->client->request('GET', '/api/map/trips?from=abc');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        // 3. Zero or negative "from"
        $this->client->request('GET', '/api/map/trips?from=0');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        // 4. Same from and to
        $this->client->request('GET', '/api/map/trips?from=1&to=1');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        // 5. Non-numeric "to"
        $this->client->request('GET', '/api/map/trips?from=1&to=invalid');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        // 6. Invalid date format
        $this->client->request('GET', '/api/map/trips?from=1&date=invalid-date');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        // 7. Invalid calendar date
        $this->client->request('GET', '/api/map/trips?from=1&date=2026-02-31');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testMapTripsReturns404WhenCityNotFound(): void
    {
        $fixtures = $this->createFixtures();
        $agadir = $fixtures['agadir'];

        // Unknown "from"
        $this->client->request('GET', '/api/map/trips?from=999999');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // Unknown "to"
        $this->client->request('GET', sprintf('/api/map/trips?from=%d&to=999999', $agadir->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
