<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AuthControllerTest extends WebTestCase
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

    private function createUser(string $email = 'youssef@covoiturage.ma', string $password = 'demo1234', array $roles = ['ROLE_USER']): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Youssef')
            ->setLastName('Benali')
            ->setPhone('+212 6 61 12 34 56')
            ->setRoles($roles);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testRegistrationSuccessAndShortPasswordRejected(): void
    {
        $crawler = $this->client->request('GET', '/inscription');
        self::assertResponseIsSuccessful();

        // 1. Mot de passe trop court (< 8 caractères)
        $form = $crawler->selectButton('S\'inscrire')->form([
            'registration_form[firstName]' => 'Amina',
            'registration_form[lastName]' => 'El Fassi',
            'registration_form[email]' => 'amina@covoiturage.ma',
            'registration_form[phone]' => '+212 6 62 00 11 22',
            'registration_form[plainPassword]' => 'court',
        ]);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);

        // 2. Inscription valide (>= 8 caractères)
        $crawler = $this->client->request('GET', '/inscription');
        $form = $crawler->selectButton('S\'inscrire')->form([
            'registration_form[firstName]' => 'Amina',
            'registration_form[lastName]' => 'El Fassi',
            'registration_form[email]' => 'amina@covoiturage.ma',
            'registration_form[phone]' => '+212 6 62 00 11 22',
            'registration_form[plainPassword]' => 'secret1234',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/connexion');
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'amina@covoiturage.ma']);
        self::assertNotNull($user);
        self::assertContains('ROLE_USER', $user->getRoles());
    }

    public function testLoginSuccessAndFailure(): void
    {
        $this->createUser('youssef@covoiturage.ma', 'demo1234');

        // 1. Connexion échouée (mauvais mot de passe)
        $crawler = $this->client->request('GET', '/connexion');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => 'youssef@covoiturage.ma',
            '_password' => 'mauvais_mdp',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/connexion');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');

        // 2. Connexion réussie
        $crawler = $this->client->request('GET', '/connexion');
        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => 'youssef@covoiturage.ma',
            '_password' => 'demo1234',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('nav', 'Youssef Benali');
    }

    public function testAccessControlForAnonymousUserAndAdminArea(): void
    {
        // Visiteur anonyme redirigé vers /connexion sur /mes-reservations et /trajets/nouveau
        $this->client->request('GET', '/mes-reservations');
        self::assertResponseRedirects('/connexion');

        $this->client->request('GET', '/trajets/nouveau');
        self::assertResponseRedirects('/connexion');

        // Utilisateur ROLE_USER reçoit 403 Forbidden sur /admin
        $normalUser = $this->createUser('user@covoiturage.ma', 'demo1234', ['ROLE_USER']);
        $this->client->loginUser($normalUser);

        $this->client->request('GET', '/mes-reservations');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);

        // Utilisateur ROLE_ADMIN accède à /admin ET aux routes ROLE_USER (hiérarchie des rôles)
        $adminUser = $this->createUser('admin@covoiturage.ma', 'demo1234', ['ROLE_ADMIN']);
        $this->client->loginUser($adminUser);

        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
    }
}
