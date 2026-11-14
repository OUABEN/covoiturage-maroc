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

class ProfileControllerTest extends WebTestCase
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
    }

    public function testPublicProfileDisplaysUserStatsAverageRatingAndReceivedReviews(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $agadir = (new City())->setName('ProfAgadir_'.$suffix)->setLatitude('30.427755')->setLongitude('-9.598107');
        $marrakech = (new City())->setName('ProfMarrakech_'.$suffix)->setLatitude('31.629472')->setLongitude('-7.981084');
        $this->em->persist($agadir);
        $this->em->persist($marrakech);

        $driver = (new User())
            ->setEmail('prof.driver.'.$suffix.'@covoit.ma')
            ->setFirstName('Rachid')
            ->setLastName('Alami '.$suffix)
            ->setPhone('+212600111222');
        $driver->setPassword($this->passwordHasher->hashPassword($driver, 'secret1234'));

        $passenger = (new User())
            ->setEmail('prof.passenger.'.$suffix.'@covoit.ma')
            ->setFirstName('Nadia')
            ->setLastName('Bennani '.$suffix)
            ->setPhone('+212600333444');
        $passenger->setPassword($this->passwordHasher->hashPassword($passenger, 'secret1234'));

        $vehicle = (new Vehicle())
            ->setOwner($driver)
            ->setBrand('Peugeot')
            ->setModel('3008')
            ->setColor('Noir')
            ->setSeats(4);

        $trip = (new Trip())
            ->setDriver($driver)
            ->setVehicle($vehicle)
            ->setSeatsTotal(3)
            ->setStatus(TripStatus::COMPLETED);

        $stop0 = (new TripStop())
            ->setCity($agadir)
            ->setStopOrder(0)
            ->setDepartureTime(new \DateTimeImmutable('-3 days 09:00'))
            ->setPriceFromPrevious('0.00');
        $stop1 = (new TripStop())
            ->setCity($marrakech)
            ->setStopOrder(1)
            ->setArrivalTime(new \DateTimeImmutable('-3 days 12:00'))
            ->setPriceFromPrevious('120.00');

        $trip->addStop($stop0)->addStop($stop1);

        $booking = (new Booking())
            ->setTrip($trip)
            ->setPassenger($passenger)
            ->setFromStop($stop0)
            ->setToStop($stop1)
            ->setSeats(1)
            ->setPrice('120.00')
            ->setStatus(BookingStatus::CONFIRMED);

        $review = (new Review())
            ->setBooking($booking)
            ->setAuthor($passenger)
            ->setTarget($driver)
            ->setRating(5)
            ->setComment('Excellent trajet sur l\'autoroute Agadir-Marrakech !');

        $this->em->persist($driver);
        $this->em->persist($passenger);
        $this->em->persist($vehicle);
        $this->em->persist($trip);
        $this->em->persist($booking);
        $this->em->persist($review);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/profil/'.$driver->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Rachid Alami '.$suffix);
        self::assertStringContainsString('5,0', $crawler->text());
        self::assertStringContainsString('Basé sur 1 avis vérifié(s)', $crawler->text());
        self::assertStringContainsString('Excellent trajet sur l\'autoroute Agadir-Marrakech !', $crawler->text());
        self::assertStringContainsString('Peugeot 3008', $crawler->text());

        // Aucune donnée sensible (email ou téléphone) ne doit être exposée sur le profil public
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString((string) $driver->getEmail(), $html);
        self::assertStringNotContainsString((string) $driver->getPhone(), $html);
        self::assertStringNotContainsString((string) $passenger->getEmail(), $html);
        self::assertStringNotContainsString((string) $passenger->getPhone(), $html);
    }
}
