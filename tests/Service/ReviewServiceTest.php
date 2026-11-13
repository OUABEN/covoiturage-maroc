<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Booking;
use App\Entity\City;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Exception\InvalidReviewException;
use App\Service\ReviewService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ReviewServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ReviewService $reviewService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->reviewService = $container->get(ReviewService::class);

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
     * @return array{0: Trip, 1: Booking, 2: User, 3: User, 4: User}
     */
    private function createReviewScenario(
        TripStatus $tripStatus = TripStatus::COMPLETED,
        BookingStatus $bookingStatus = BookingStatus::CONFIRMED,
    ): array {
        $driver = (new User())
            ->setEmail('driver.rev@test.ma')
            ->setFirstName('Salma')
            ->setLastName('Chraïbi')
            ->setPhone('+212600000001')
            ->setPassword('hashed');
        $this->em->persist($driver);

        $passenger = (new User())
            ->setEmail('passenger.rev@test.ma')
            ->setFirstName('Mehdi')
            ->setLastName('Tazi')
            ->setPhone('+212600000002')
            ->setPassword('hashed');
        $this->em->persist($passenger);

        $thirdParty = (new User())
            ->setEmail('outsider.rev@test.ma')
            ->setFirstName('Hamza')
            ->setLastName('Idrissi')
            ->setPhone('+212600000003')
            ->setPassword('hashed');
        $this->em->persist($thirdParty);

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

        $trip = (new Trip())
            ->setDriver($driver)
            ->setVehicle($vehicle)
            ->setSeatsTotal(4)
            ->setStatus($tripStatus);

        $stop0 = (new TripStop())
            ->setCity($agadir)
            ->setStopOrder(0)
            ->setDepartureTime(new \DateTimeImmutable('-2 days 08:00'))
            ->setPriceFromPrevious('0.00');

        $stop1 = (new TripStop())
            ->setCity($marrakech)
            ->setStopOrder(1)
            ->setArrivalTime(new \DateTimeImmutable('-2 days 11:00'))
            ->setPriceFromPrevious('120.00');

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
            ->setPrice('120.00')
            ->setStatus($bookingStatus);
        $this->em->persist($booking);
        $this->em->flush();

        return [$trip, $booking, $driver, $passenger, $thirdParty];
    }

    public function testMutualReviewsSucceedWhenTripIsCompleted(): void
    {
        [, $booking, $driver, $passenger] = $this->createReviewScenario();

        $rev1 = $this->reviewService->createReview($booking, $passenger, 5, 'Conductrice très ponctuelle.');
        $rev2 = $this->reviewService->createReview($booking, $driver, 4, 'Passager courtois.');

        self::assertSame($driver, $rev1->getTarget());
        self::assertSame(5, $rev1->getRating());
        self::assertSame($passenger, $rev2->getTarget());
        self::assertSame(4, $rev2->getRating());
    }

    public function testReviewRejectedBeforeTripIsCompleted(): void
    {
        [, $booking, , $passenger] = $this->createReviewScenario(TripStatus::PUBLISHED);

        $this->expectException(InvalidReviewException::class);
        $this->expectExceptionMessage('Impossible de déposer un avis tant que le trajet n\'est pas terminé.');
        $this->reviewService->createReview($booking, $passenger, 5, 'Trop tôt !');
    }

    public function testDuplicateReviewBySameAuthorRejected(): void
    {
        [, $booking, , $passenger] = $this->createReviewScenario();

        $this->reviewService->createReview($booking, $passenger, 5, 'Premier avis');

        $this->expectException(InvalidReviewException::class);
        $this->expectExceptionMessage('Vous avez déjà déposé un avis pour cette réservation.');
        $this->reviewService->createReview($booking, $passenger, 4, 'Deuxième avis');
    }

    public function testThirdPartyReviewRejected(): void
    {
        [, $booking, , , $thirdParty] = $this->createReviewScenario();

        $this->expectException(InvalidReviewException::class);
        $this->expectExceptionMessage('Un utilisateur tiers ne peut pas évaluer cette réservation.');
        $this->reviewService->createReview($booking, $thirdParty, 3, 'Avis intrus');
    }
}
