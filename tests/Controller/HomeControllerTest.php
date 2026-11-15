<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\City;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\TripStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class HomeControllerTest extends WebTestCase
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

    public function testHomePageDisplaysSixPopularTripsAndThreeSteps(): void
    {
        $driver = (new User())
            ->setEmail('driver.home@covoiturage.ma')
            ->setFirstName('Karim')
            ->setLastName('Elamrani')
            ->setPhone('+212 6 70 00 00 01');
        $driver->setPassword($this->passwordHasher->hashPassword($driver, 'demo1234'));
        $this->em->persist($driver);

        $vehicle = (new Vehicle())
            ->setOwner($driver)
            ->setBrand('Dacia')
            ->setModel('Duster')
            ->setSeats(4)
            ->setColor('Gris');
        $this->em->persist($vehicle);

        $cities = [];
        $cityNames = ['Agadir', 'Marrakech', 'Casablanca', 'Rabat', 'Fès', 'Tanger', 'Meknès'];
        foreach ($cityNames as $name) {
            $city = (new City())->setName($name)->setLatitude('31.000000')->setLongitude('-8.000000');
            $this->em->persist($city);
            $cities[] = $city;
        }

        $now = new \DateTimeImmutable();

        // Create 6 upcoming published trips
        for ($i = 1; $i <= 6; ++$i) {
            $trip = (new Trip())
                ->setDriver($driver)
                ->setVehicle($vehicle)
                ->setSeatsTotal(4)
                ->setStatus(TripStatus::PUBLISHED)
                ->setCreatedAt($now);
            $this->em->persist($trip);

            $depTime = $now->modify(sprintf('+%d days', $i))->setTime(8 + $i, 0);

            $stop1 = (new TripStop())
                ->setTrip($trip)
                ->setCity($cities[0])
                ->setStopOrder(1)
                ->setDepartureTime($depTime)
                ->setPriceFromPrevious('0.00');
            $this->em->persist($stop1);
            $trip->addStop($stop1);

            $stop2 = (new TripStop())
                ->setTrip($trip)
                ->setCity($cities[$i])
                ->setStopOrder(2)
                ->setArrivalTime($depTime->modify('+3 hours'))
                ->setDepartureTime(null)
                ->setPriceFromPrevious('120.00');
            $this->em->persist($stop2);
            $trip->addStop($stop2);
        }

        $this->em->flush();

        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        // 3 steps of "Comment ça marche ?"
        self::assertSelectorTextContains('h2#how-it-works-heading', 'Comment ça marche ?');
        self::assertStringContainsString('1. Choisissez votre portion', $crawler->html());
        self::assertStringContainsString('2. Réservez au prix juste', $crawler->html());
        self::assertStringContainsString('3. Voyagez &amp; évaluez', $crawler->html());

        // 6 popular trips cards
        $popularCards = $crawler->filter('section[aria-labelledby="popular-trips-heading"] .card');
        self::assertCount(6, $popularCards);

        // Verification of card content: Driver initials, price "À partir de", etc.
        self::assertStringContainsString('Karim E.', $popularCards->first()->html());
        self::assertStringContainsString('À partir de', $popularCards->first()->html());
        self::assertStringContainsString('120.00 MAD', $popularCards->first()->html());
        self::assertStringContainsString('2 étapes', $popularCards->first()->html());
    }
}
