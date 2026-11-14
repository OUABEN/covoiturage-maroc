<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Admin\UserCrudController;
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

class AdminDashboardControllerTest extends WebTestCase
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

    public function testAdminAccessControlReadOnlyBookingCancelViaServiceAndPasswordHashing(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $regularUser = (new User())
            ->setEmail('user.admin.'.$suffix.'@covoit.ma')
            ->setFirstName('Mehdi')
            ->setLastName('User '.$suffix)
            ->setPhone('+212600000002')
            ->setRoles(['ROLE_USER']);
        $regularUser->setPassword($this->passwordHasher->hashPassword($regularUser, 'secret1234'));

        $admin = (new User())
            ->setEmail('admin.test.'.$suffix.'@covoit.ma')
            ->setFirstName('Admin')
            ->setLastName('Atlas '.$suffix)
            ->setPhone('+212600000001')
            ->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'secret1234'));

        $agadir = (new City())->setName('AdmAgadir_'.$suffix)->setLatitude('30.427755')->setLongitude('-9.598107');
        $marrakech = (new City())->setName('AdmMarrakech_'.$suffix)->setLatitude('31.629472')->setLongitude('-7.981084');

        $vehicle = (new Vehicle())
            ->setOwner($admin)
            ->setBrand('Dacia')
            ->setModel('Logan')
            ->setColor('Blanc')
            ->setSeats(4);

        $depDate = new \DateTimeImmutable('+3 days 09:00');
        $trip = (new Trip())
            ->setDriver($admin)
            ->setVehicle($vehicle)
            ->setSeatsTotal(3)
            ->setStatus(TripStatus::PUBLISHED);

        $stop0 = (new TripStop())
            ->setCity($agadir)
            ->setStopOrder(0)
            ->setDepartureTime($depDate)
            ->setOriginalDepartureTime($depDate)
            ->setPriceFromPrevious('0.00');
        $stop1 = (new TripStop())
            ->setCity($marrakech)
            ->setStopOrder(1)
            ->setArrivalTime($depDate->modify('+3 hours'))
            ->setOriginalArrivalTime($depDate->modify('+3 hours'))
            ->setPriceFromPrevious('120.00');
        $trip->addStop($stop0)->addStop($stop1);

        $booking = (new Booking())
            ->setTrip($trip)
            ->setPassenger($regularUser)
            ->setFromStop($stop0)
            ->setToStop($stop1)
            ->setSeats(1)
            ->setPrice('120.00')
            ->setStatus(BookingStatus::CONFIRMED);

        $this->em->persist($regularUser);
        $this->em->persist($admin);
        $this->em->persist($agadir);
        $this->em->persist($marrakech);
        $this->em->persist($vehicle);
        $this->em->persist($stop0);
        $this->em->persist($stop1);
        $this->em->persist($trip);
        $this->em->persist($booking);
        $this->em->flush();

        // 1. Regular ROLE_USER is denied access (403 Forbidden) on /admin
        $this->client->loginUser($regularUser);
        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // 2. ROLE_ADMIN can access /admin and CRUD index pages
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('AtlasCovoit Maroc — Back-office d\'administration', $crawler->text());
        self::assertStringContainsString('BookingService::book()', $crawler->text());

        $this->client->request('GET', '/admin/city');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/booking');
        self::assertResponseIsSuccessful();

        // 3. Direct creation of Booking (/admin/booking/new) is forbidden (read-only)
        $this->client->request('GET', '/admin/booking/new');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // 4. Admin cancellation action delegates to CancellationService
        $this->client->request('POST', sprintf('/admin/booking/%d/cancel-service', $booking->getId()));
        self::assertResponseRedirects('/admin/booking');

        $this->em->clear();
        $reloadedBooking = $this->em->find(Booking::class, $booking->getId());
        self::assertSame(BookingStatus::CANCELLED_BY_PASSENGER, $reloadedBooking?->getStatus());
        self::assertSame('120.00', $reloadedBooking?->getRefundAmount());

        // 5. UserCrudController::persistEntity hashes plain password upon creation
        /** @var UserCrudController $userCrud */
        $userCrud = static::getContainer()->get(UserCrudController::class);
        $newAdminUser = (new User())
            ->setEmail('created.in.admin.'.$suffix.'@covoit.ma')
            ->setFirstName('Karim')
            ->setLastName('Created')
            ->setPhone('+212600999888')
            ->setPassword('plain_password_123');
        $userCrud->persistEntity($this->em, $newAdminUser);

        self::assertNotSame('plain_password_123', $newAdminUser->getPassword());
        self::assertTrue($this->passwordHasher->isPasswordValid($newAdminUser, 'plain_password_123'));
    }
}
