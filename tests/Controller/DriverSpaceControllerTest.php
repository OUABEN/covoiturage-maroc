<?php

declare(strict_types=1);

namespace App\Tests\Controller;

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

    public function testDriverPublishValidAndInvalidTripsVotersAndCancelRefundsConfirmedBookings(): void
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

        $passenger = (new User())
            ->setEmail('passenger.space.'.$suffix.'@covoit.ma')
            ->setFirstName('Amina')
            ->setLastName('Passagere '.$suffix)
            ->setPhone('+212600889901');
        $passenger->setPassword($this->passwordHasher->hashPassword($passenger, 'secret1234'));

        $intruder = (new User())
            ->setEmail('intruder.space.'.$suffix.'@covoit.ma')
            ->setFirstName('Omar')
            ->setLastName('Tiers '.$suffix)
            ->setPhone('+212600889902');
        $intruder->setPassword($this->passwordHasher->hashPassword($intruder, 'secret1234'));

        $this->em->persist($driver);
        $this->em->persist($passenger);
        $this->em->persist($intruder);
        $this->em->flush();

        $this->client->loginUser($driver);

        // 1. Add a 4-seat vehicle on /mes-vehicules
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
        self::assertStringContainsString('Renault Kangoo', $crawler->text());

        /** @var Vehicle $vehicle */
        $vehicle = $this->em->getRepository(Vehicle::class)->findOneBy(['owner' => $driver]);
        self::assertNotNull($vehicle);

        $crawler = $this->client->request('GET', '/trajets/nouveau');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $dep0 = (new \DateTimeImmutable('+2 days 08:00'))->format('Y-m-d\TH:i');
        $arr1 = (new \DateTimeImmutable('+2 days 11:00'))->format('Y-m-d\TH:i');
        $dep1 = (new \DateTimeImmutable('+2 days 11:15'))->format('Y-m-d\TH:i');
        $arr2 = (new \DateTimeImmutable('+2 days 14:00'))->format('Y-m-d\TH:i');

        // 2a. Invalid publication: seatsTotal > vehicle.seats (6 > 4)
        $this->client->request('POST', '/trajets/nouveau', [
            '_token' => $token,
            'vehicle_id' => (string) $vehicle->getId(),
            'seats_total' => 6,
            'stops' => [
                0 => ['city_id' => (string) $agadir->getId(), 'departure_time' => $dep0, 'price_from_previous' => '0.00'],
                1 => ['city_id' => (string) $marrakech->getId(), 'arrival_time' => $arr1, 'price_from_previous' => '120.00'],
            ],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-danger');

        // 2b. Invalid publication: only 1 stop
        $this->client->request('POST', '/trajets/nouveau', [
            '_token' => $token,
            'vehicle_id' => (string) $vehicle->getId(),
            'seats_total' => 3,
            'stops' => [
                0 => ['city_id' => (string) $agadir->getId(), 'departure_time' => $dep0, 'price_from_previous' => '0.00'],
            ],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-danger');

        // 2c. Invalid publication: non-increasing times (arrival before departure)
        $badArr1 = (new \DateTimeImmutable('+2 days 07:00'))->format('Y-m-d\TH:i');
        $this->client->request('POST', '/trajets/nouveau', [
            '_token' => $token,
            'vehicle_id' => (string) $vehicle->getId(),
            'seats_total' => 3,
            'stops' => [
                0 => ['city_id' => (string) $agadir->getId(), 'departure_time' => $dep0, 'price_from_previous' => '0.00'],
                1 => ['city_id' => (string) $marrakech->getId(), 'arrival_time' => $badArr1, 'price_from_previous' => '120.00'],
            ],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-danger');

        // 3. Valid 3-stop trip publication
        $this->client->request('POST', '/trajets/nouveau', [
            '_token' => $token,
            'vehicle_id' => (string) $vehicle->getId(),
            'seats_total' => 3,
            'stops' => [
                0 => ['city_id' => (string) $agadir->getId(), 'departure_time' => $dep0, 'price_from_previous' => '0.00'],
                1 => ['city_id' => (string) $marrakech->getId(), 'arrival_time' => $arr1, 'departure_time' => $dep1, 'price_from_previous' => '120.00'],
                2 => ['city_id' => (string) $casa->getId(), 'arrival_time' => $arr2, 'departure_time' => '', 'price_from_previous' => '100.00'],
            ],
        ]);
        self::assertResponseRedirects('/mes-trajets');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Votre trajet a été publié avec succès', $crawler->text());

        /** @var Trip|null $trip */
        $trip = $this->em->getRepository(Trip::class)->findOneBy(['driver' => $driver]);
        self::assertNotNull($trip);
        /** @var TripStop $firstStop */
        $firstStop = $trip->getStops()->first();
        /** @var TripStop $secondStop */
        $secondStop = $trip->getStops()->get(1);

        // Attach a confirmed booking on segment 0 -> 1 (120.00 MAD)
        $booking = (new Booking())
            ->setTrip($trip)
            ->setPassenger($passenger)
            ->setFromStop($firstStop)
            ->setToStop($secondStop)
            ->setSeats(1)
            ->setPrice('120.00')
            ->setStatus(BookingStatus::CONFIRMED);
        $this->em->persist($booking);
        $this->em->flush();

        // 4. VehicleVoter and TripVoter deny 403 to third-party intruder
        $this->client->loginUser($intruder);
        $this->client->request('POST', sprintf('/mes-vehicules/%d/supprimer', $vehicle->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('POST', sprintf('/mes-trajets/%d/annuler', $trip->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('POST', sprintf('/mes-trajets/arrets/%d/horaire', $firstStop->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // 5. Driver cancels the trip -> all confirmed bookings become CANCELLED_BY_DRIVER with 100 % refund
        $this->client->loginUser($driver);
        $crawler = $this->client->request('GET', '/mes-trajets');
        $cancelFormNode = $crawler->filter(sprintf('form[action="/mes-trajets/%d/annuler"]', $trip->getId()));
        $cancelToken = $cancelFormNode->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', sprintf('/mes-trajets/%d/annuler', $trip->getId()), [
            '_token' => $cancelToken,
        ]);
        self::assertResponseRedirects('/mes-trajets');

        $this->em->clear();
        $reloadedTrip = $this->em->find(Trip::class, $trip->getId());
        $reloadedBooking = $this->em->find(Booking::class, $booking->getId());
        self::assertSame(TripStatus::CANCELLED, $reloadedTrip?->getStatus());
        self::assertSame(BookingStatus::CANCELLED_BY_DRIVER, $reloadedBooking?->getStatus());
        self::assertSame('120.00', $reloadedBooking?->getRefundAmount());
    }
}
