<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use App\Entity\Vehicle;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserAndVehicleFixtures extends Fixture
{
    public const DEMO_PASSWORD = 'demo1234';

    public const USER_ADMIN = 'user_admin';
    public const USER_DRIVER_1 = 'user_driver_1';
    public const USER_DRIVER_2 = 'user_driver_2';
    public const USER_DRIVER_3 = 'user_driver_3';
    public const USER_DRIVER_4 = 'user_driver_4';
    public const USER_PASSENGER_1 = 'user_passenger_1';
    public const USER_PASSENGER_2 = 'user_passenger_2';
    public const USER_PASSENGER_3 = 'user_passenger_3';

    public const VEHICLE_1 = 'vehicle_1';
    public const VEHICLE_2 = 'vehicle_2';
    public const VEHICLE_3 = 'vehicle_3';
    public const VEHICLE_4 = 'vehicle_4';
    public const VEHICLE_5 = 'vehicle_5';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = new \DateTimeImmutable();

        $usersData = [
            self::USER_ADMIN => [
                'email' => 'admin@covoiturage.ma',
                'firstName' => 'Karim',
                'lastName' => 'El Mansouri',
                'phone' => '+212 6 60 00 11 22',
                'roles' => ['ROLE_ADMIN'],
                'createdAt' => $now->modify('-60 days'),
            ],
            self::USER_DRIVER_1 => [
                'email' => 'youssef.benali@covoiturage.ma',
                'firstName' => 'Youssef',
                'lastName' => 'Benali',
                'phone' => '+212 6 61 12 34 56',
                'roles' => ['ROLE_USER'],
                'createdAt' => $now->modify('-45 days'),
            ],
            self::USER_DRIVER_2 => [
                'email' => 'fatima.alaoui@covoiturage.ma',
                'firstName' => 'Fatima-Zahra',
                'lastName' => 'Alaoui',
                'phone' => '+212 6 62 23 45 67',
                'roles' => ['ROLE_USER'],
                'createdAt' => $now->modify('-40 days'),
            ],
            self::USER_DRIVER_3 => [
                'email' => 'omar.aitbrahim@covoiturage.ma',
                'firstName' => 'Omar',
                'lastName' => 'Aït Brahim',
                'phone' => '+212 6 63 34 56 78',
                'roles' => ['ROLE_USER'],
                'createdAt' => $now->modify('-35 days'),
            ],
            self::USER_DRIVER_4 => [
                'email' => 'salma.chraibi@covoiturage.ma',
                'firstName' => 'Salma',
                'lastName' => 'Chraïbi',
                'phone' => '+212 6 64 45 67 89',
                'roles' => ['ROLE_USER'],
                'createdAt' => $now->modify('-30 days'),
            ],
            self::USER_PASSENGER_1 => [
                'email' => 'mehdi.tazi@covoiturage.ma',
                'firstName' => 'Mehdi',
                'lastName' => 'Tazi',
                'phone' => '+212 6 65 56 78 90',
                'roles' => ['ROLE_USER'],
                'createdAt' => $now->modify('-25 days'),
            ],
            self::USER_PASSENGER_2 => [
                'email' => 'nadia.berrada@covoiturage.ma',
                'firstName' => 'Nadia',
                'lastName' => 'Berrada',
                'phone' => '+212 6 66 67 89 01',
                'roles' => ['ROLE_USER'],
                'createdAt' => $now->modify('-20 days'),
            ],
            self::USER_PASSENGER_3 => [
                'email' => 'hamza.idrissi@covoiturage.ma',
                'firstName' => 'Hamza',
                'lastName' => 'Idrissi',
                'phone' => '+212 6 67 78 90 12',
                'roles' => ['ROLE_USER'],
                'createdAt' => $now->modify('-15 days'),
            ],
        ];

        /** @var array<string, User> $users */
        $users = [];

        foreach ($usersData as $reference => $data) {
            $user = (new User())
                ->setEmail($data['email'])
                ->setFirstName($data['firstName'])
                ->setLastName($data['lastName'])
                ->setPhone($data['phone'])
                ->setRoles($data['roles'])
                ->setCreatedAt($data['createdAt']);

            $user->setPassword(
                $this->passwordHasher->hashPassword($user, self::DEMO_PASSWORD)
            );

            $manager->persist($user);
            $this->addReference($reference, $user);
            $users[$reference] = $user;
        }

        $vehiclesData = [
            self::VEHICLE_1 => [
                'owner' => $users[self::USER_DRIVER_1],
                'brand' => 'Dacia',
                'model' => 'Duster',
                'color' => 'Gris Comète',
                'seats' => 4,
            ],
            self::VEHICLE_2 => [
                'owner' => $users[self::USER_DRIVER_1],
                'brand' => 'Renault',
                'model' => 'Clio 5',
                'color' => 'Blanc Glacier',
                'seats' => 3,
            ],
            self::VEHICLE_3 => [
                'owner' => $users[self::USER_DRIVER_2],
                'brand' => 'Peugeot',
                'model' => '3008',
                'color' => 'Noir Perla',
                'seats' => 4,
            ],
            self::VEHICLE_4 => [
                'owner' => $users[self::USER_DRIVER_3],
                'brand' => 'Volkswagen',
                'model' => 'Golf 8',
                'color' => 'Bleu Atlantique',
                'seats' => 4,
            ],
            self::VEHICLE_5 => [
                'owner' => $users[self::USER_DRIVER_4],
                'brand' => 'Hyundai',
                'model' => 'Tucson',
                'color' => 'Argent Métallisé',
                'seats' => 4,
            ],
        ];

        foreach ($vehiclesData as $reference => $data) {
            $vehicle = (new Vehicle())
                ->setOwner($data['owner'])
                ->setBrand($data['brand'])
                ->setModel($data['model'])
                ->setColor($data['color'])
                ->setSeats($data['seats']);

            $manager->persist($vehicle);
            $this->addReference($reference, $vehicle);
        }

        $manager->flush();
    }
}
