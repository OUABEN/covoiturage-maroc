<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Exception\InvalidBookingException;
use App\Exception\NotEnoughSeatsException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

class BookingService
{
    private const MAX_DEADLOCK_RETRIES = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AvailabilityService $availabilityService,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Réserve $seats place(s) sur la portion [$from, $to] du trajet $trip pour $passenger.
     * Exécuté dans une transaction atomique avec verrouillage pessimiste (SELECT ... FOR UPDATE)
     * sur la ligne Trip et réessai automatique en cas de deadlock (max 3 tentatives).
     */
    public function book(Trip $trip, User $passenger, TripStop $from, TripStop $to, int $seats): Booking
    {
        if ($seats < 1) {
            throw new InvalidBookingException('Le nombre de places réservées doit être supérieur ou égal à 1.');
        }

        if ($from->getTrip() !== $trip || $to->getTrip() !== $trip) {
            throw new InvalidBookingException('Les arrêts de départ et d\'arrivée doivent appartenir au trajet réservé.');
        }

        $fromOrder = $from->getStopOrder();
        $toOrder = $to->getStopOrder();

        if (null === $fromOrder || null === $toOrder || $fromOrder >= $toOrder) {
            throw new InvalidBookingException('L\'ordre de l\'arrêt de départ doit être strictement inférieur à celui de l\'arrêt d\'arrivée.');
        }

        for ($attempt = 1; $attempt <= self::MAX_DEADLOCK_RETRIES; ++$attempt) {
            $this->em->beginTransaction();

            try {
                /** @var Trip|null $lockedTrip */
                $lockedTrip = $this->em->find(Trip::class, $trip->getId(), LockMode::PESSIMISTIC_WRITE);
                if (null === $lockedTrip) {
                    throw new InvalidBookingException('Trajet introuvable.');
                }

                // Force le rechargement depuis MySQL sous verrou exclusif (SELECT ... FOR UPDATE)
                // afin d'éviter qu'une version obsolète présente dans l'Identity Map de Doctrine
                // (ex. statut du trajet passé à CANCELLED par une transaction concurrente) ne fausse la validation.
                $this->em->refresh($lockedTrip, LockMode::PESSIMISTIC_WRITE);

                if (TripStatus::PUBLISHED !== $lockedTrip->getStatus()) {
                    throw new InvalidBookingException('Ce trajet n\'est pas ouvert à la réservation.');
                }

                $driver = $lockedTrip->getDriver();
                if (null !== $driver && ($driver === $passenger || (null !== $driver->getId() && $driver->getId() === $passenger->getId()))) {
                    throw new InvalidBookingException('Le conducteur ne peut pas réserver son propre trajet.');
                }

                $vehicle = $lockedTrip->getVehicle();
                if (null === $vehicle || $lockedTrip->getSeatsTotal() > $vehicle->getSeats()) {
                    throw new InvalidBookingException('Le nombre total de places du trajet dépasse la capacité du véhicule.');
                }

                $departureTime = $from->getDepartureTime() ?? $from->getArrivalTime();
                if (null === $departureTime || $departureTime <= $this->clock->now()) {
                    throw new InvalidBookingException('Le départ de cette étape est déjà passé.');
                }

                $availableSeats = $this->availabilityService->availableSeats($lockedTrip, $from, $to);
                if ($seats > $availableSeats) {
                    throw new NotEnoughSeatsException(sprintf(
                        'Places disponibles insuffisantes sur la portion demandée (%d demandée(s), %d disponible(s)).',
                        $seats,
                        $availableSeats
                    ));
                }

                $totalPrice = $this->calculatePortionPrice($lockedTrip, $fromOrder, $toOrder, $seats);

                $booking = (new Booking())
                    ->setTrip($lockedTrip)
                    ->setPassenger($passenger)
                    ->setFromStop($from)
                    ->setToStop($to)
                    ->setSeats($seats)
                    ->setPrice($totalPrice)
                    ->setStatus(BookingStatus::CONFIRMED)
                    ->setCreatedAt($this->clock->now());

                $this->em->persist($booking);
                $this->em->flush();
                $this->em->commit();

                return $booking;
            } catch (DeadlockException|LockWaitTimeoutException $e) {
                if ($this->em->getConnection()->isTransactionActive()) {
                    $this->em->rollback();
                }

                if (self::MAX_DEADLOCK_RETRIES === $attempt) {
                    throw $e;
                }

                usleep(random_int(15_000, 60_000));
            } catch (\Throwable $e) {
                if ($this->em->getConnection()->isTransactionActive()) {
                    $this->em->rollback();
                }

                throw $e;
            }
        }

        throw new InvalidBookingException('Échec de la réservation après plusieurs tentatives.');
    }

    /**
     * Calcule via bcmath : (somme des priceFromPrevious des segments dans ]$fromOrder, $toOrder]) × $seats.
     */
    public function calculatePortionPrice(Trip $trip, int $fromOrder, int $toOrder, int $seats): string
    {
        $unitPrice = '0.00';

        foreach ($trip->getStops() as $stop) {
            $order = $stop->getStopOrder();
            if (null !== $order && $order > $fromOrder && $order <= $toOrder) {
                $unitPrice = bcadd($unitPrice, $stop->getPriceFromPrevious(), 2);
            }
        }

        return bcmul($unitPrice, (string) $seats, 2);
    }
}
