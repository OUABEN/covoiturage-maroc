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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SearchAndBookingControllerTest extends WebTestCase
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
     * @return array{0: Trip, 1: list<TripStop>, 2: list<City>, 3: User, 4: User, 5: \DateTimeImmutable}
     */
    private function createSearchAndBookingFixtures(int $seatsTotal = 2): array
    {
        $driver = (new User())
            ->setEmail('driver.web@covoiturage.ma')
            ->setFirstName('Youssef')
            ->setLastName('Benali')
            ->setPhone('+212 6 61 00 00 01');
        $driver->setPassword($this->passwordHasher->hashPassword($driver, 'demo1234'));
        $this->em->persist($driver);

        $passenger = (new User())
            ->setEmail('passenger.web@covoiturage.ma')
            ->setFirstName('Mehdi')
            ->setLastName('Tazi')
            ->setPhone('+212 6 65 00 00 02');
        $passenger->setPassword($this->passwordHasher->hashPassword($passenger, 'demo1234'));
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
        $casablanca = (new City())->setName('Casablanca')->setLatitude('33.573110')->setLongitude('-7.589843');
        $this->em->persist($agadir);
        $this->em->persist($marrakech);
        $this->em->persist($casablanca);

        $day = (new \DateTimeImmutable('+2 days'))->setTime(9, 0);

        $trip = (new Trip())
            ->setDriver($driver)
            ->setVehicle($vehicle)
            ->setSeatsTotal($seatsTotal)
            ->setStatus(TripStatus::PUBLISHED);

        $stop0 = (new TripStop())
            ->setCity($agadir)
            ->setStopOrder(0)
            ->setDepartureTime($day)
            ->setOriginalDepartureTime($day)
            ->setPriceFromPrevious('0.00');

        $stop1 = (new TripStop())
            ->setCity($marrakech)
            ->setStopOrder(1)
            ->setArrivalTime($day->modify('+3 hours'))
            ->setOriginalArrivalTime($day->modify('+3 hours'))
            ->setDepartureTime($day->modify('+3 hours 15 minutes'))
            ->setOriginalDepartureTime($day->modify('+3 hours 15 minutes'))
            ->setPriceFromPrevious('120.00');

        $stop2 = (new TripStop())
            ->setCity($casablanca)
            ->setStopOrder(2)
            ->setArrivalTime($day->modify('+6 hours'))
            ->setOriginalArrivalTime($day->modify('+6 hours'))
            ->setPriceFromPrevious('100.00');

        $trip->addStop($stop0)->addStop($stop1)->addStop($stop2);
        $this->em->persist($stop0);
        $this->em->persist($stop1);
        $this->em->persist($stop2);
        $this->em->persist($trip);
        $this->em->flush();

        return [$trip, [$stop0, $stop1, $stop2], [$agadir, $marrakech, $casablanca], $driver, $passenger, $day];
    }

    public function testHomePageSearchDisplaysMatchingTripPortionAndPrice(): void
    {
        [, , $cities, , , $day] = $this->createSearchAndBookingFixtures(3);
        [$agadir, , $casablanca] = $cities;

        $this->client->request('GET', '/', [
            'from' => $agadir->getId(),
            'to' => $casablanca->getId(),
            'date' => $day->format('Y-m-d'),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Youssef Benali');
        self::assertSelectorTextContains('body', '220.00 MAD');
        self::assertSelectorTextContains('body', '3 place(s)');

        // Recherche sans résultat (date sans trajet)
        $this->client->request('GET', '/recherche', [
            'from' => $agadir->getId(),
            'to' => $casablanca->getId(),
            'date' => $day->modify('+10 days')->format('Y-m-d'),
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aucun trajet disponible pour cette recherche');
    }

    public function testBookingSucceededAndBookingRefusedWhenNoSeatsRemain(): void
    {
        [$trip, $stops, , $driver, $passenger] = $this->createSearchAndBookingFixtures(1);

        // 0. Le conducteur ne peut pas réserver son propre trajet
        $this->client->loginUser($driver);
        $crawler = $this->client->request('GET', sprintf('/trajets/%d', $trip->getId()));
        self::assertResponseIsSuccessful();
        $driverForm = $crawler->selectButton('Confirmer la réservation')->form([
            'from_stop' => (string) $stops[0]->getId(),
            'to_stop' => (string) $stops[1]->getId(),
            'seats' => '1',
        ]);
        $this->client->submit($driverForm);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');

        $this->client->loginUser($passenger);

        // 1. Accès à la page détail et réservation de la dernière place sur Agadir -> Marrakech (120.00 MAD)
        $crawler = $this->client->request('GET', sprintf('/trajets/%d', $trip->getId()));
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Confirmer la réservation')->form([
            'from_stop' => (string) $stops[0]->getId(),
            'to_stop' => (string) $stops[1]->getId(),
            'seats' => '1',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $this->client->followRedirect();

        self::assertSelectorExists('.alert-success');
        self::assertSelectorTextContains('.alert-success', '120.00 MAD');

        $confirmedCount = $this->em->getRepository(Booking::class)->count([
            'trip' => $trip->getId(),
            'status' => BookingStatus::CONFIRMED,
        ]);
        self::assertSame(1, $confirmedCount);

        // 2. Seconde réservation sur Agadir -> Casablanca : refusée car le segment Agadir -> Marrakech est plein (0 place restante)
        $crawler = $this->client->request('GET', sprintf('/trajets/%d', $trip->getId()));
        $form = $crawler->selectButton('Confirmer la réservation')->form([
            'from_stop' => (string) $stops[0]->getId(),
            'to_stop' => (string) $stops[2]->getId(),
            'seats' => '1',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $this->client->followRedirect();

        self::assertSelectorExists('.alert-danger');
        self::assertSelectorTextContains('.alert-danger', 'Places disponibles insuffisantes');
    }
}
