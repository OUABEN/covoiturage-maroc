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

class EndToEndJourneyTest extends WebTestCase
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

    public function testCompleteEndToEndUserJourney(): void
    {
        // 0. Base fixtures : Cities, Driver, Admin, and initial published trip
        $agadir = (new City())->setName('Agadir')->setLatitude('30.427755')->setLongitude('-9.598107');
        $marrakech = (new City())->setName('Marrakech')->setLatitude('31.629472')->setLongitude('-7.981084');
        $casa = (new City())->setName('Casablanca')->setLatitude('33.573110')->setLongitude('-7.589843');
        $this->em->persist($agadir);
        $this->em->persist($marrakech);
        $this->em->persist($casa);

        $driver = (new User())
            ->setEmail('driver.e2e@covoiturage.ma')
            ->setFirstName('Youssef')
            ->setLastName('Benali')
            ->setPhone('+212600112233')
            ->setRoles(['ROLE_USER']);
        $driver->setPassword($this->passwordHasher->hashPassword($driver, 'demo1234'));
        $this->em->persist($driver);

        $admin = (new User())
            ->setEmail('admin.e2e@covoiturage.ma')
            ->setFirstName('Admin')
            ->setLastName('Root')
            ->setPhone('+212600998877')
            ->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'demo1234'));
        $this->em->persist($admin);

        $vehicle = (new Vehicle())
            ->setOwner($driver)
            ->setBrand('Dacia')
            ->setModel('Duster')
            ->setColor('Gris')
            ->setSeats(4);
        $this->em->persist($vehicle);

        // Pre-existing published trip (Agadir -> Marrakech -> Casablanca)
        $initialTrip = (new Trip())
            ->setDriver($driver)
            ->setVehicle($vehicle)
            ->setSeatsTotal(3)
            ->setStatus(TripStatus::PUBLISHED);
        $stop0 = (new TripStop())->setCity($agadir)->setStopOrder(0)->setDepartureTime(new \DateTimeImmutable('+2 days 08:00'))->setPriceFromPrevious('0.00');
        $stop1 = (new TripStop())->setCity($marrakech)->setStopOrder(1)->setArrivalTime(new \DateTimeImmutable('+2 days 11:30'))->setDepartureTime(new \DateTimeImmutable('+2 days 12:00'))->setPriceFromPrevious('120.00');
        $stop2 = (new TripStop())->setCity($casa)->setStopOrder(2)->setArrivalTime(new \DateTimeImmutable('+2 days 15:30'))->setPriceFromPrevious('100.00');
        $initialTrip->addStop($stop0)->addStop($stop1)->addStop($stop2);
        $this->em->persist($stop0);
        $this->em->persist($stop1);
        $this->em->persist($stop2);
        $this->em->persist($initialTrip);

        // Pre-existing completed trip for review test
        $completedTrip = (new Trip())
            ->setDriver($driver)
            ->setVehicle($vehicle)
            ->setSeatsTotal(3)
            ->setStatus(TripStatus::COMPLETED);
        $cStop0 = (new TripStop())->setCity($agadir)->setStopOrder(0)->setDepartureTime(new \DateTimeImmutable('-2 days 08:00'))->setPriceFromPrevious('0.00');
        $cStop1 = (new TripStop())->setCity($marrakech)->setStopOrder(1)->setArrivalTime(new \DateTimeImmutable('-2 days 11:30'))->setPriceFromPrevious('120.00');
        $completedTrip->addStop($cStop0)->addStop($cStop1);
        $this->em->persist($cStop0);
        $this->em->persist($cStop1);
        $this->em->persist($completedTrip);

        $this->em->flush();
        $completedTripId = $completedTrip->getId();
        $cStop0Id = $cStop0->getId();
        $cStop1Id = $cStop1->getId();

        // =========================================================================
        // ÉTAPE 1 : Visiteur recherche un trajet sur le formulaire (/recherche)
        // =========================================================================
        $crawler = $this->client->request('GET', '/recherche', [
            'from' => $agadir->getId(),
            'to' => $marrakech->getId(),
        ]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Youssef', $crawler->text());
        self::assertStringContainsString('Agadir', $crawler->text());
        self::assertStringContainsString('Marrakech', $crawler->text());

        // =========================================================================
        // ÉTAPE 2 : Inscription d'un nouvel utilisateur (/inscription)
        // =========================================================================
        $crawler = $this->client->request('GET', '/inscription');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('S\'inscrire')->form([
            'registration_form[firstName]' => 'Karim',
            'registration_form[lastName]' => 'El Idrissi',
            'registration_form[email]' => 'karim.e2e@test.ma',
            'registration_form[phone]' => '+212611223344',
            'registration_form[plainPassword]' => 'Passw0rd123!',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/connexion');

        // =========================================================================
        // ÉTAPE 3 : Connexion du nouvel utilisateur (/connexion)
        // =========================================================================
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $loginForm = $crawler->selectButton('Se connecter')->form([
            '_username' => 'karim.e2e@test.ma',
            '_password' => 'Passw0rd123!',
        ]);
        $this->client->submit($loginForm);
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        // Récupérer l'entité du passager fraîchement connecté
        $passenger = $this->em->getRepository(User::class)->findOneBy(['email' => 'karim.e2e@test.ma']);
        self::assertNotNull($passenger);

        // =========================================================================
        // ÉTAPE 4 : Réservation sur le trajet publié (/trajets/{id}/reserver)
        // =========================================================================
        $crawler = $this->client->request('GET', sprintf('/trajets/%d', $initialTrip->getId()));
        self::assertResponseIsSuccessful();

        $bookingForm = $crawler->selectButton('Confirmer la réservation')->form([
            'from_stop' => (string) $stop0->getId(),
            'to_stop' => (string) $stop1->getId(),
            'seats' => '2',
        ]);
        $this->client->submit($bookingForm);
        self::assertResponseRedirects();

        $this->em->clear();
        $booking = $this->em->getRepository(Booking::class)->findOneBy([
            'trip' => $initialTrip->getId(),
            'passenger' => $passenger->getId(),
        ]);
        self::assertNotNull($booking);
        self::assertSame(BookingStatus::CONFIRMED, $booking->getStatus());
        self::assertSame(2, $booking->getSeats());
        self::assertSame('240.00', $booking->getPrice());

        // =========================================================================
        // ÉTAPE 5 : Annulation avec remboursement affiché avant confirmation
        // =========================================================================
        $crawler = $this->client->request('GET', sprintf('/mes-reservations/%d/annuler', $booking->getId()));
        self::assertResponseIsSuccessful();
        // Vérifie que le taux (100 %) et le montant (240.00 MAD) sont affichés préventivement
        self::assertSelectorTextContains('body', '100 % (240.00 MAD)');

        $cancelForm = $crawler->selectButton('Confirmer l\'annulation (100 % : 240.00 MAD)')->form();
        $this->client->submit($cancelForm);
        self::assertResponseRedirects('/mes-reservations');

        $this->em->clear();
        $cancelledBooking = $this->em->find(Booking::class, $booking->getId());
        self::assertSame(BookingStatus::CANCELLED_BY_PASSENGER, $cancelledBooking?->getStatus());
        self::assertSame('240.00', $cancelledBooking?->getRefundAmount());

        // =========================================================================
        // ÉTAPE 6 : Le conducteur publie un nouveau trajet (/trajets/nouveau)
        // =========================================================================
        $this->client->loginUser($driver);

        $crawler = $this->client->request('GET', '/trajets/nouveau');
        self::assertResponseIsSuccessful();

        $depDate = (new \DateTimeImmutable('+3 days'))->format('Y-m-d');
        $token = $crawler->filter('form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/trajets/nouveau', [
            '_token' => $token,
            'vehicle_id' => (string) $vehicle->getId(),
            'seats_total' => 3,
            'stops' => [
                0 => [
                    'city_id' => (string) $marrakech->getId(),
                    'departure_time' => sprintf('%sT09:00', $depDate),
                    'price_from_previous' => '0.00',
                ],
                1 => [
                    'city_id' => (string) $casa->getId(),
                    'arrival_time' => sprintf('%sT12:30', $depDate),
                    'price_from_previous' => '110.00',
                ],
            ],
        ]);
        self::assertResponseRedirects('/mes-trajets');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $driverTrips = $this->em->getRepository(Trip::class)->findBy(['driver' => $driver->getId()], ['id' => 'DESC']);
        $newTrip = $driverTrips[0] ?? null;
        self::assertNotNull($newTrip);
        self::assertSame(TripStatus::PUBLISHED, $newTrip->getStatus());

        // =========================================================================
        // ÉTAPE 7 : Le conducteur annule ce trajet (/mes-trajets/{id}/annuler)
        // =========================================================================
        $crawler = $this->client->request('GET', '/mes-trajets');
        $cancelTripToken = $crawler->filter(sprintf('form[action$="/mes-trajets/%d/annuler"] input[name="_token"]', $newTrip->getId()))->attr('value');

        $this->client->request('POST', sprintf('/mes-trajets/%d/annuler', $newTrip->getId()), [
            '_token' => $cancelTripToken,
        ]);
        self::assertResponseRedirects('/mes-trajets');

        $this->em->clear();
        $reloadedNewTrip = $this->em->find(Trip::class, $newTrip->getId());
        self::assertSame(TripStatus::CANCELLED, $reloadedNewTrip?->getStatus());

        // =========================================================================
        // ÉTAPE 8 : Avis après trajet terminé (/mes-reservations/{id}/avis)
        // =========================================================================
        // Créer une réservation confirmée pour le passager sur le trajet terminé
        $passenger = $this->em->find(User::class, $passenger->getId());
        $completedTripReloaded = $this->em->find(Trip::class, $completedTripId);
        $cStop0Reloaded = $this->em->find(TripStop::class, $cStop0Id);
        $cStop1Reloaded = $this->em->find(TripStop::class, $cStop1Id);

        $completedBooking = (new Booking())
            ->setTrip($completedTripReloaded)
            ->setPassenger($passenger)
            ->setFromStop($cStop0Reloaded)
            ->setToStop($cStop1Reloaded)
            ->setSeats(1)
            ->setPrice('120.00')
            ->setStatus(BookingStatus::CONFIRMED);
        $this->em->persist($completedBooking);
        $this->em->flush();

        $this->client->loginUser($passenger);
        $crawler = $this->client->request('GET', '/mes-reservations');
        self::assertResponseIsSuccessful();

        $reviewForm = $crawler->selectButton('Envoyer l\'avis')->form([
            'rating' => '5',
            'comment' => 'Trajet formidable et ponctuel !',
        ]);
        $this->client->submit($reviewForm);
        self::assertResponseRedirects('/mes-reservations');

        $this->em->clear();
        $savedReview = $this->em->getRepository(Review::class)->findOneBy(['booking' => $completedBooking->getId()]);
        self::assertNotNull($savedReview);
        self::assertSame(5, $savedReview->getRating());
        self::assertSame('Trajet formidable et ponctuel !', $savedReview->getComment());

        // =========================================================================
        // ÉTAPE 9 : Admin ouvre le back-office (/admin)
        // =========================================================================
        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('body');

        // =========================================================================
        // ÉTAPE 10 : Recherche par carte via l'API (/api/map/trips)
        // =========================================================================
        $this->client->request('GET', sprintf('/api/map/trips?from=%d&to=%d', $agadir->getId(), $marrakech->getId()));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        /** @var array{count: int, trips: list<array<string, mixed>>} $json */
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('count', $json);
        self::assertArrayHasKey('trips', $json);
        self::assertGreaterThanOrEqual(1, $json['count']);

        // Vérification de sécurité : aucune fuite de données privées (email, phone)
        $rawResponse = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('@', $rawResponse);
        self::assertStringNotContainsString('+212', $rawResponse);
    }
}
