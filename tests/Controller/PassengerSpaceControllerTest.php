<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Booking;
use App\Entity\City;
use App\Entity\Review;
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

class PassengerSpaceControllerTest extends WebTestCase
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

    private function createBookingWithDepartureOffset(
        User $driver,
        User $passenger,
        Vehicle $vehicle,
        City $fromCity,
        City $toCity,
        string $modifyExpr,
        string $price = '200.00',
        TripStatus $tripStatus = TripStatus::PUBLISHED,
    ): Booking {
        $depDate = (new \DateTimeImmutable())->modify($modifyExpr);
        $trip = (new Trip())
            ->setDriver($driver)
            ->setVehicle($vehicle)
            ->setSeatsTotal(4)
            ->setStatus($tripStatus);

        $stop0 = (new TripStop())
            ->setCity($fromCity)
            ->setStopOrder(0)
            ->setDepartureTime($depDate)
            ->setOriginalDepartureTime($depDate)
            ->setPriceFromPrevious('0.00');
        $stop1 = (new TripStop())
            ->setCity($toCity)
            ->setStopOrder(1)
            ->setArrivalTime($depDate->modify('+3 hours'))
            ->setOriginalArrivalTime($depDate->modify('+3 hours'))
            ->setPriceFromPrevious($price);

        $trip->addStop($stop0)->addStop($stop1);
        $this->em->persist($stop0);
        $this->em->persist($stop1);
        $this->em->persist($trip);

        $booking = (new Booking())
            ->setTrip($trip)
            ->setPassenger($passenger)
            ->setFromStop($stop0)
            ->setToStop($stop1)
            ->setSeats(1)
            ->setPrice($price)
            ->setStatus(BookingStatus::CONFIRMED);

        $this->em->persist($booking);
        $this->em->flush();

        return $booking;
    }

    public function testCancellationAtAllThreeTiersThirdPartyForbiddenAndReviewRules(): void
    {
        $driver = (new User())
            ->setEmail('driver.pass@covoiturage.ma')
            ->setFirstName('Salma')
            ->setLastName('Chraïbi')
            ->setPhone('+212 6 64 00 00 01');
        $driver->setPassword($this->passwordHasher->hashPassword($driver, 'demo1234'));
        $this->em->persist($driver);

        $passenger = (new User())
            ->setEmail('passenger.pass@covoiturage.ma')
            ->setFirstName('Mehdi')
            ->setLastName('Tazi')
            ->setPhone('+212 6 65 00 00 02');
        $passenger->setPassword($this->passwordHasher->hashPassword($passenger, 'demo1234'));
        $this->em->persist($passenger);

        $intruder = (new User())
            ->setEmail('intruder.pass@covoiturage.ma')
            ->setFirstName('Karim')
            ->setLastName('Intruder')
            ->setPhone('+212 6 66 00 00 03');
        $intruder->setPassword($this->passwordHasher->hashPassword($intruder, 'demo1234'));
        $this->em->persist($intruder);

        $vehicle = (new Vehicle())
            ->setOwner($driver)
            ->setBrand('Hyundai')
            ->setModel('Tucson')
            ->setColor('Argent')
            ->setSeats(4);
        $this->em->persist($vehicle);

        $agadir = (new City())->setName('Agadir')->setLatitude('30.427755')->setLongitude('-9.598107');
        $marrakech = (new City())->setName('Marrakech')->setLatitude('31.629472')->setLongitude('-7.981084');
        $this->em->persist($agadir);
        $this->em->persist($marrakech);
        $this->em->flush();

        $booking100 = $this->createBookingWithDepartureOffset($driver, $passenger, $vehicle, $agadir, $marrakech, '+3 days', '240.00');
        $booking50 = $this->createBookingWithDepartureOffset($driver, $passenger, $vehicle, $agadir, $marrakech, '+10 hours', '200.00');
        $booking0 = $this->createBookingWithDepartureOffset($driver, $passenger, $vehicle, $agadir, $marrakech, '+1 hour', '160.00');
        $bookingActive = $this->createBookingWithDepartureOffset($driver, $passenger, $vehicle, $agadir, $marrakech, '+2 days', '120.00');
        $bookingCompleted = $this->createBookingWithDepartureOffset($driver, $passenger, $vehicle, $agadir, $marrakech, '-2 days', '120.00', TripStatus::COMPLETED);

        // 1. Accès refusé (403) à un tiers (BookingVoter)
        $this->client->loginUser($intruder);
        $this->client->request('GET', sprintf('/mes-reservations/%d/annuler', $booking100->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('POST', sprintf('/mes-reservations/%d/annuler', $booking100->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('POST', sprintf('/mes-reservations/%d/avis', $bookingCompleted->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // 2. Connexion du propriétaire et vérification des 3 paliers de remboursement (100 %, 50 %, 0 %)
        $this->client->loginUser($passenger);

        // Palier 100 % (> 24h)
        $crawler = $this->client->request('GET', sprintf('/mes-reservations/%d/annuler', $booking100->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '100 % (240.00 MAD)');
        $form100 = $crawler->selectButton('Confirmer l\'annulation (100 % : 240.00 MAD)')->form();
        $this->client->submit($form100);
        self::assertResponseRedirects('/mes-reservations');

        // Palier 50 % (entre 2h et 24h)
        $crawler = $this->client->request('GET', sprintf('/mes-reservations/%d/annuler', $booking50->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '50 % (100.00 MAD)');
        $form50 = $crawler->selectButton('Confirmer l\'annulation (50 % : 100.00 MAD)')->form();
        $this->client->submit($form50);
        self::assertResponseRedirects('/mes-reservations');

        // Palier 0 % (< 2h)
        $crawler = $this->client->request('GET', sprintf('/mes-reservations/%d/annuler', $booking0->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '0 % (0.00 MAD)');
        $form0 = $crawler->selectButton('Confirmer l\'annulation (0 % : 0.00 MAD)')->form();
        $this->client->submit($form0);
        self::assertResponseRedirects('/mes-reservations');

        $this->em->clear();
        self::assertSame('240.00', $this->em->find(Booking::class, $booking100->getId())?->getRefundAmount());
        self::assertSame('100.00', $this->em->find(Booking::class, $booking50->getId())?->getRefundAmount());
        self::assertSame('0.00', $this->em->find(Booking::class, $booking0->getId())?->getRefundAmount());

        // 3a. Avis refusé avant la fin du trajet (trajet repassé en PUBLISHED au moment du POST)
        $crawler = $this->client->request('GET', '/mes-reservations');
        $reviewFormPreCompletion = $crawler->selectButton('Envoyer l\'avis')->form([
            'rating' => '5',
            'comment' => 'Tentative avant fin du trajet',
        ]);

        $tripToRevert = $this->em->find(Trip::class, $bookingCompleted->getTrip()->getId());
        $tripToRevert?->setStatus(TripStatus::PUBLISHED);
        $this->em->flush();

        $this->client->submit($reviewFormPreCompletion);
        self::assertResponseRedirects('/mes-reservations');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');
        self::assertSame(0, $this->em->getRepository(Review::class)->count(['booking' => $bookingCompleted->getId()]));

        // 3b. Avis accepté après la fin du trajet (trajet COMPLETED)
        $tripToComplete = $this->em->find(Trip::class, $bookingCompleted->getTrip()->getId());
        $tripToComplete?->setStatus(TripStatus::COMPLETED);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/mes-reservations');
        $reviewFormCompleted = $crawler->selectButton('Envoyer l\'avis')->form([
            'rating' => '5',
            'comment' => 'Trajet impeccable !',
        ]);
        $this->client->submit($reviewFormCompleted);
        self::assertResponseRedirects('/mes-reservations');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-success');

        $reviewCount = $this->em->getRepository(Review::class)->count(['booking' => $bookingCompleted->getId()]);
        self::assertSame(1, $reviewCount);
    }
}
