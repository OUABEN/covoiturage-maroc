<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\City;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Enum\TripStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class DriverSpaceControllerTest extends WebTestCase
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

    public function testDriverCanAddVehiclePublishMultiStopTripUpdateStopTimeAndCancelOrComplete(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $agadir = (new City())->setName('DrvAgadir_'.$suffix)->setLatitude('30.427755')->setLongitude('-9.598107');
        $marrakech = (new City())->setName('DrvMarrakech_'.$suffix)->setLatitude('31.629472')->setLongitude('-7.981084');
        $casa = (new City())->setName('DrvCasa_'.$suffix)->setLatitude('33.573110')->setLongitude('-7.589843');
        $this->em->persist($agadir);
        $this->em->persist($marrakech);
        $this->em->persist($casa);

        $driver = (new User())
            ->setEmail('driver.space.'.$suffix.'@covoit.ma')
            ->setFirstName('Youssef')
            ->setLastName('Conducteur '.$suffix)
            ->setPhone('+212600889900');
        $driver->setPassword($this->passwordHasher->hashPassword($driver, 'secret1234'));
        $this->em->persist($driver);
        $this->em->flush();

        $this->client->loginUser($driver);

        // 1. Add a vehicle on /mes-vehicules
        $crawler = $this->client->request('GET', '/mes-vehicules');
        self::assertResponseIsSuccessful();

        $vehicleForm = $crawler->selectButton('Ajouter le véhicule')->form([
            'brand' => 'Renault',
            'model' => 'Kangoo',
            'color' => 'Blanc',
            'seats' => '4',
        ]);
        $this->client->submit($vehicleForm);
        self::assertResponseRedirects('/mes-vehicules');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Renault Kangoo', $crawler->text());

        // 2. Publish a 3-stop trip via /trajets/nouveau
        $crawler = $this->client->request('GET', '/trajets/nouveau');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $vehicleId = $crawler->filter('select[name="vehicle_id"] option')->first()->attr('value');

        $dep0 = (new \DateTimeImmutable('+2 days 08:00'))->format('Y-m-d\TH:i');
        $arr1 = (new \DateTimeImmutable('+2 days 11:00'))->format('Y-m-d\TH:i');
        $dep1 = (new \DateTimeImmutable('+2 days 11:15'))->format('Y-m-d\TH:i');
        $arr2 = (new \DateTimeImmutable('+2 days 14:00'))->format('Y-m-d\TH:i');

        $this->client->request('POST', '/trajets/nouveau', [
            '_token' => $token,
            'vehicle_id' => $vehicleId,
            'seats_total' => 3,
            'stops' => [
                0 => [
                    'city_id' => (string) $agadir->getId(),
                    'departure_time' => $dep0,
                    'price_from_previous' => '0.00',
                ],
                1 => [
                    'city_id' => (string) $marrakech->getId(),
                    'arrival_time' => $arr1,
                    'departure_time' => $dep1,
                    'price_from_previous' => '120.00',
                ],
                2 => [
                    'city_id' => (string) $casa->getId(),
                    'arrival_time' => $arr2,
                    'departure_time' => '',
                    'price_from_previous' => '100.00',
                ],
            ],
        ]);
        self::assertResponseRedirects('/mes-trajets');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Votre trajet a été publié avec succès', $crawler->text());
        self::assertStringContainsString('DrvAgadir_'.$suffix, $crawler->text());
        self::assertStringContainsString('DrvCasa_'.$suffix, $crawler->text());

        // Find the published trip and its first stop
        /** @var Trip|null $trip */
        $trip = $this->em->getRepository(Trip::class)->findOneBy(['driver' => $driver]);
        self::assertNotNull($trip);
        self::assertSame(TripStatus::PUBLISHED, $trip->getStatus());
        self::assertCount(3, $trip->getStops());

        /** @var TripStop $firstStop */
        $firstStop = $trip->getStops()->first();

        // 3. Update first stop departure time (+15 min, within ±30 min tolerance)
        $updateFormNode = $crawler->filter(sprintf('form[action="/mes-trajets/arrets/%d/horaire"]', $firstStop->getId()));
        self::assertCount(1, $updateFormNode);
        $updateToken = $updateFormNode->filter('input[name="_token"]')->attr('value');

        $newDep0 = (new \DateTimeImmutable('+2 days 08:15'))->format('Y-m-d\TH:i');
        $this->client->request('POST', sprintf('/mes-trajets/arrets/%d/horaire', $firstStop->getId()), [
            '_token' => $updateToken,
            'departure_time' => $newDep0,
        ]);
        self::assertResponseRedirects('/mes-trajets');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Horaire de l\'étape mis à jour', $crawler->text());

        // 4. Cancel the trip by driver
        $cancelFormNode = $crawler->filter(sprintf('form[action="/mes-trajets/%d/annuler"]', $trip->getId()));
        self::assertCount(1, $cancelFormNode);
        $cancelToken = $cancelFormNode->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', sprintf('/mes-trajets/%d/annuler', $trip->getId()), [
            '_token' => $cancelToken,
        ]);
        self::assertResponseRedirects('/mes-trajets');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Le trajet a été annulé', $crawler->text());

        $this->em->clear();
        $reloadedTrip = $this->em->find(Trip::class, $trip->getId());
        self::assertSame(TripStatus::CANCELLED, $reloadedTrip?->getStatus());
    }
}
