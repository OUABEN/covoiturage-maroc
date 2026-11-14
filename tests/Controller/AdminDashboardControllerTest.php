<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
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

    public function testAdminCanAccessDashboardAndCrudPagesWhileBookingCreationIsDisabled(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $admin = (new User())
            ->setEmail('admin.test.'.$suffix.'@covoit.ma')
            ->setFirstName('Admin')
            ->setLastName('Atlas '.$suffix)
            ->setPhone('+212600000001')
            ->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'secret1234'));
        $this->em->persist($admin);
        $this->em->flush();

        $this->client->loginUser($admin);

        // 1. Dashboard /admin
        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('AtlasCovoit Maroc — Back-office d\'administration', $crawler->text());
        self::assertStringContainsString('BookingService::book()', $crawler->text());

        // 2. City CRUD index
        $this->client->request('GET', '/admin/city');
        self::assertResponseIsSuccessful();

        // 3. Booking CRUD index (read-only)
        $this->client->request('GET', '/admin/booking');
        self::assertResponseIsSuccessful();

        // 4. Attempting to access /admin/booking/new is forbidden even for ROLE_ADMIN
        // because Action::NEW is disabled in BookingCrudController
        $this->client->request('GET', '/admin/booking/new');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }
}
