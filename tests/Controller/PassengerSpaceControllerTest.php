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

    /**
     * @return array{driver: User, passenger: User, intruder: User, vehicle: Vehicle, agadir: City, marrakech: City}
     */
    private function createScenarioUsers(): array
    {
        $suffix = bin2hex(random_bytes(4));

        $driver = (new User())
            ->setEmail('driver.pass.'.$suffix.'@covoiturage.ma')
            ->setFirstName('Salma')
            ->setLastName('Chraïbi')
            ->setPhone('+212 6 64 00 00 01');
        $driver->setPassword($this->passwordHasher->hashPassword($driver, 'demo1234'));
        $this->em->persist($driver);

        $passenger = (new User())
            ->setEmail('passenger.pass.'.$suffix.'@covoiturage.ma')
            ->setFirstName('Mehdi')
            ->setLastName('Tazi')
            ->setPhone('+212 6 65 00 00 02');
        $passenger->setPassword($this->passwordHasher->hashPassword($passenger, 'demo1234'));
        $this->em->persist($passenger);

        $intruder = (new User())
            ->setEmail('intruder.pass.'.$suffix.'@covoiturage.ma')
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

        $agadir = (new City())->setName('Agadir_'.$suffix)->setLatitude('30.427755')->setLongitude('-9.598107');
        $marrakech = (new City())->setName('Marrakech_'.$suffix)->setLatitude('31.629472')->setLongitude('-7.981084');
        $this->em->persist($agadir);
        $this->em->persist($marrakech);
        $this->em->flush();

        return [
            'driver' => $driver,
            'passenger' => $passenger,
            'intruder' => $intruder,
            'vehicle' => $vehicle,
            'agadir' => $agadir,
            'marrakech' => $marrakech,
        ];
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

    public function testIdorAccessDeniedToThirdPartyForCancellationAndReview(): void
    {
        $u = $this->createScenarioUsers();
        $booking = $this->createBookingWithDepartureOffset($u['driver'], $u['passenger'], $u['vehicle'], $u['agadir'], $u['marrakech'], '+3 days');

        $this->client->loginUser($u['intruder']);

        // GET confirm cancel of another user's booking -> 403 Forbidden
        $this->client->request('GET', sprintf('/mes-reservations/%d/annuler', $booking->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // POST cancel of another user's booking -> 403 Forbidden
        $this->client->request('POST', sprintf('/mes-reservations/%d/annuler', $booking->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // POST review of another user's booking -> 403 Forbidden
        $this->client->request('POST', sprintf('/mes-reservations/%d/avis', $booking->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testMissingCsrfTokenRejectsBookingCancellation(): void
    {
        $u = $this->createScenarioUsers();
        $booking = $this->createBookingWithDepartureOffset($u['driver'], $u['passenger'], $u['vehicle'], $u['agadir'], $u['marrakech'], '+3 days');

        $this->client->loginUser($u['passenger']);

        // POST cancel without _token
        $this->client->request('POST', sprintf('/mes-reservations/%d/annuler', $booking->getId()), [
            '_token' => 'invalid_csrf_token',
        ]);
        self::assertResponseRedirects('/mes-reservations');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');

        // Booking remains CONFIRMED
        $this->em->clear();
        $reloaded = $this->em->find(Booking::class, $booking->getId());
        self::assertSame(BookingStatus::CONFIRMED, $reloaded?->getStatus());
    }

    public function testMissingCsrfTokenRejectsReviewSubmission(): void
    {
        $u = $this->createScenarioUsers();
        $booking = $this->createBookingWithDepartureOffset($u['driver'], $u['passenger'], $u['vehicle'], $u['agadir'], $u['marrakech'], '-2 days', '120.00', TripStatus::COMPLETED);

        $this->client->loginUser($u['passenger']);

        $this->client->request('POST', sprintf('/mes-reservations/%d/avis', $booking->getId()), [
            '_token' => 'invalid_token',
            'rating' => 5,
            'comment' => 'Commentaire sans CSRF',
        ]);
        self::assertResponseRedirects('/mes-reservations');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');

        self::assertSame(0, $this->em->getRepository(Review::class)->count(['booking' => $booking->getId()]));
    }

    public function testDoubleCancellationIsRejected(): void
    {
        $u = $this->createScenarioUsers();
        $booking = $this->createBookingWithDepartureOffset($u['driver'], $u['passenger'], $u['vehicle'], $u['agadir'], $u['marrakech'], '+3 days');

        $this->client->loginUser($u['passenger']);

        // First cancellation -> Success
        $crawler = $this->client->request('GET', sprintf('/mes-reservations/%d/annuler', $booking->getId()));
        $form = $crawler->selectButton('Confirmer l\'annulation (100 % : 200.00 MAD)')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/mes-reservations');

        $this->em->clear();
        $reloaded = $this->em->find(Booking::class, $booking->getId());
        self::assertSame(BookingStatus::CANCELLED_BY_PASSENGER, $reloaded?->getStatus());

        // Second cancellation attempt on already cancelled booking
        $this->client->request('POST', sprintf('/mes-reservations/%d/annuler', $booking->getId()), [
            '_token' => $form->get('_token')->getValue(),
        ]);
        // Handled either by voter/controller flash danger or redirect
        self::assertResponseRedirects('/mes-reservations');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');
    }

    public function testCancellationRefundTier100PercentMoreThan24Hours(): void
    {
        $u = $this->createScenarioUsers();
        $booking = $this->createBookingWithDepartureOffset($u['driver'], $u['passenger'], $u['vehicle'], $u['agadir'], $u['marrakech'], '+3 days', '240.00');

        $this->client->loginUser($u['passenger']);

        $crawler = $this->client->request('GET', sprintf('/mes-reservations/%d/annuler', $booking->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '100 % (240.00 MAD)');

        $form = $crawler->selectButton('Confirmer l\'annulation (100 % : 240.00 MAD)')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/mes-reservations');

        $this->em->clear();
        $reloaded = $this->em->find(Booking::class, $booking->getId());
        self::assertSame('240.00', $reloaded?->getRefundAmount());
        self::assertSame(BookingStatus::CANCELLED_BY_PASSENGER, $reloaded?->getStatus());
    }

    public function testCancellationRefundTier50PercentBetween2And24Hours(): void
    {
        $u = $this->createScenarioUsers();
        $booking = $this->createBookingWithDepartureOffset($u['driver'], $u['passenger'], $u['vehicle'], $u['agadir'], $u['marrakech'], '+10 hours', '200.00');

        $this->client->loginUser($u['passenger']);

        $crawler = $this->client->request('GET', sprintf('/mes-reservations/%d/annuler', $booking->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '50 % (100.00 MAD)');

        $form = $crawler->selectButton('Confirmer l\'annulation (50 % : 100.00 MAD)')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/mes-reservations');

        $this->em->clear();
        $reloaded = $this->em->find(Booking::class, $booking->getId());
        self::assertSame('100.00', $reloaded?->getRefundAmount());
    }

    public function testCancellationRefundTier0PercentLessThan2Hours(): void
    {
        $u = $this->createScenarioUsers();
        $booking = $this->createBookingWithDepartureOffset($u['driver'], $u['passenger'], $u['vehicle'], $u['agadir'], $u['marrakech'], '+1 hour', '160.00');

        $this->client->loginUser($u['passenger']);

        $crawler = $this->client->request('GET', sprintf('/mes-reservations/%d/annuler', $booking->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '0 % (0.00 MAD)');

        $form = $crawler->selectButton('Confirmer l\'annulation (0 % : 0.00 MAD)')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/mes-reservations');

        $this->em->clear();
        $reloaded = $this->em->find(Booking::class, $booking->getId());
        self::assertSame('0.00', $reloaded?->getRefundAmount());
    }

    public function testReviewBeforeTripCompletionIsRejected(): void
    {
        $u = $this->createScenarioUsers();
        // Create booking on completed trip to extract valid CSRF form token
        $booking = $this->createBookingWithDepartureOffset($u['driver'], $u['passenger'], $u['vehicle'], $u['agadir'], $u['marrakech'], '-2 days', '120.00', TripStatus::COMPLETED);

        $this->client->loginUser($u['passenger']);

        $crawler = $this->client->request('GET', '/mes-reservations');
        $reviewForm = $crawler->selectButton('Envoyer l\'avis')->form([
            'rating' => '5',
            'comment' => 'Tentative avant fin du trajet',
        ]);

        // Revert trip status to PUBLISHED before submitting
        $trip = $this->em->find(Trip::class, $booking->getTrip()->getId());
        $trip?->setStatus(TripStatus::PUBLISHED);
        $this->em->flush();

        $this->client->submit($reviewForm);
        self::assertResponseRedirects('/mes-reservations');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');

        self::assertSame(0, $this->em->getRepository(Review::class)->count(['booking' => $booking->getId()]));
    }

    public function testReviewAfterTripCompletedIsAcceptedAndDuplicateIsRejected(): void
    {
        $u = $this->createScenarioUsers();
        $booking = $this->createBookingWithDepartureOffset($u['driver'], $u['passenger'], $u['vehicle'], $u['agadir'], $u['marrakech'], '-2 days', '120.00', TripStatus::COMPLETED);

        $this->client->loginUser($u['passenger']);

        $crawler = $this->client->request('GET', '/mes-reservations');
        $reviewForm = $crawler->selectButton('Envoyer l\'avis')->form([
            'rating' => '5',
            'comment' => 'Trajet impeccable !',
        ]);
        $this->client->submit($reviewForm);
        self::assertResponseRedirects('/mes-reservations');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-success');

        self::assertSame(1, $this->em->getRepository(Review::class)->count(['booking' => $booking->getId()]));

        // Duplicate review attempt on same booking by same user -> rejected
        $token = $reviewForm->get('_token')->getValue();
        $this->client->request('POST', sprintf('/mes-reservations/%d/avis', $booking->getId()), [
            '_token' => $token,
            'rating' => 4,
            'comment' => 'Deuxième avis non autorisé',
        ]);
        self::assertResponseRedirects('/mes-reservations');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');

        self::assertSame(1, $this->em->getRepository(Review::class)->count(['booking' => $booking->getId()]));
    }
}
