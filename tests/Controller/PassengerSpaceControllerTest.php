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
     * @return array{0: User, 1: Booking, 2: Booking}
     */
    private function createPassengerFixtures(): array
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

        // 1. Trajet futur (+3 jours, > 24h => remboursement 100 %)
        $futureDate = new \DateTimeImmutable('+3 days 10:00');
        $upcomingTrip = (new Trip())
            ->setDriver($driver)
            ->setVehicle($vehicle)
            ->setSeatsTotal(4)
            ->setStatus(TripStatus::PUBLISHED);

        $uStop0 = (new TripStop())
            ->setCity($agadir)
            ->setStopOrder(0)
            ->setDepartureTime($futureDate)
            ->setOriginalDepartureTime($futureDate)
            ->setPriceFromPrevious('0.00');
        $uStop1 = (new TripStop())
            ->setCity($marrakech)
            ->setStopOrder(1)
            ->setArrivalTime($futureDate->modify('+3 hours'))
            ->setOriginalArrivalTime($futureDate->modify('+3 hours'))
            ->setPriceFromPrevious('120.00');
        $upcomingTrip->addStop($uStop0)->addStop($uStop1);
        $this->em->persist($uStop0);
        $this->em->persist($uStop1);
        $this->em->persist($upcomingTrip);

        $upcomingBooking = (new Booking())
            ->setTrip($upcomingTrip)
            ->setPassenger($passenger)
            ->setFromStop($uStop0)
            ->setToStop($uStop1)
            ->setSeats(2)
            ->setPrice('240.00')
            ->setStatus(BookingStatus::CONFIRMED);
        $this->em->persist($upcomingBooking);

        // 2. Trajet terminé (-2 jours, COMPLETED)
        $pastDate = new \DateTimeImmutable('-2 days 09:00');
        $completedTrip = (new Trip())
            ->setDriver($driver)
            ->setVehicle($vehicle)
            ->setSeatsTotal(4)
            ->setStatus(TripStatus::COMPLETED);

        $cStop0 = (new TripStop())
            ->setCity($agadir)
            ->setStopOrder(0)
            ->setDepartureTime($pastDate)
            ->setOriginalDepartureTime($pastDate)
            ->setPriceFromPrevious('0.00');
        $cStop1 = (new TripStop())
            ->setCity($marrakech)
            ->setStopOrder(1)
            ->setArrivalTime($pastDate->modify('+3 hours'))
            ->setOriginalArrivalTime($pastDate->modify('+3 hours'))
            ->setPriceFromPrevious('120.00');
        $completedTrip->addStop($cStop0)->addStop($cStop1);
        $this->em->persist($cStop0);
        $this->em->persist($cStop1);
        $this->em->persist($completedTrip);

        $completedBooking = (new Booking())
            ->setTrip($completedTrip)
            ->setPassenger($passenger)
            ->setFromStop($cStop0)
            ->setToStop($cStop1)
            ->setSeats(1)
            ->setPrice('120.00')
            ->setStatus(BookingStatus::CONFIRMED);
        $this->em->persist($completedBooking);

        $this->em->flush();

        return [$passenger, $upcomingBooking, $completedBooking];
    }

    public function testPassengerCanPreviewRefundCancelUpcomingBookingAndReviewCompletedTrip(): void
    {
        [$passenger, $upcomingBooking, $completedBooking] = $this->createPassengerFixtures();

        $this->client->loginUser($passenger);

        $crawler = $this->client->request('GET', '/mes-reservations');
        self::assertResponseIsSuccessful();

        // Vérifie l'affichage préventif du remboursement (100 % : 240.00 MAD)
        self::assertSelectorTextContains('body', '100 % (240.00 MAD)');

        // 1. Annulation de la réservation à venir
        $cancelForm = $crawler->selectButton('Annuler (remboursement 100 % : 240.00 MAD)')->form();
        $this->client->submit($cancelForm);
        self::assertResponseRedirects('/mes-reservations');
        $this->client->followRedirect();

        self::assertSelectorExists('.alert-success');
        $this->em->clear();
        $reloadedBooking = $this->em->find(Booking::class, $upcomingBooking->getId());
        self::assertSame(BookingStatus::CANCELLED_BY_PASSENGER, $reloadedBooking->getStatus());
        self::assertSame('240.00', $reloadedBooking->getRefundAmount());

        // 2. Dépôt d'avis sur le trajet COMPLETED
        $crawler = $this->client->request('GET', '/mes-reservations');
        $reviewForm = $crawler->selectButton('Envoyer l\'avis')->form([
            'rating' => '5',
            'comment' => 'Trajet impeccable !',
        ]);
        $this->client->submit($reviewForm);
        self::assertResponseRedirects('/mes-reservations');
        $this->client->followRedirect();

        self::assertSelectorExists('.alert-success');
        $reviewCount = $this->em->getRepository(Review::class)->count(['booking' => $completedBooking->getId()]);
        self::assertSame(1, $reviewCount);
    }
}
