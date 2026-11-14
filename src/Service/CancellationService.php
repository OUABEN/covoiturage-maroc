<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Exception\InvalidCancellationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

class CancellationService
{
    private const SECONDS_24_HOURS = 86_400;
    private const SECONDS_2_HOURS = 7_200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Annulation d'une réservation par le passager :
     * - Si l'horaire d'un arrêt de la portion réservée a été décalé de > ±30 min par rapport
     *   à son horaire initial (originalDepartureTime / originalArrivalTime) : remboursement 100 %
     * - Sinon, selon le préavis avant le départ de fromStop :
     *   - > 24h avant le départ : remboursement 100 %
     *   - entre 24h et 2h       : remboursement 50 %
     *   - < 2h                  : remboursement 0 %
     */
    public function cancelByPassenger(Booking $booking, User $passenger): Booking
    {
        $bookingPassenger = $booking->getPassenger();
        if (null === $bookingPassenger || ($bookingPassenger !== $passenger && $bookingPassenger->getId() !== $passenger->getId())) {
            throw new InvalidCancellationException('Seul le passager titulaire peut annuler cette réservation.');
        }

        if (BookingStatus::CONFIRMED !== $booking->getStatus()) {
            throw new InvalidCancellationException('Seule une réservation confirmée peut être annulée.');
        }

        $trip = $booking->getTrip();
        if (null === $trip || TripStatus::PUBLISHED !== $trip->getStatus()) {
            throw new InvalidCancellationException('Le trajet associé n\'est plus actif.');
        }

        $fromStop = $booking->getFromStop();
        $departureTime = $fromStop?->getDepartureTime() ?? $fromStop?->getArrivalTime();
        if (null === $departureTime) {
            throw new InvalidCancellationException('Horaire de départ introuvable pour cette réservation.');
        }

        $now = $this->clock->now();
        $secondsUntilDeparture = $departureTime->getTimestamp() - $now->getTimestamp();

        if ($secondsUntilDeparture <= 0) {
            throw new InvalidCancellationException('Impossible d\'annuler une réservation après l\'heure de départ.');
        }

        $preview = $this->previewPassengerRefund($booking);

        $booking
            ->setStatus(BookingStatus::CANCELLED_BY_PASSENGER)
            ->setRefundAmount($preview['amount'])
            ->setCancelledAt($now);

        $this->em->persist($booking);
        $this->em->flush();

        return $booking;
    }

    /**
     * Calcule le remboursement théorique (pourcentage 100/50/0 et montant en MAD)
     * pour affichage préventif avant confirmation ou application lors de l'annulation.
     *
     * @return array{percentage: int, amount: string}
     */
    public function previewPassengerRefund(Booking $booking): array
    {
        $fromStop = $booking->getFromStop();
        $departureTime = $fromStop?->getDepartureTime() ?? $fromStop?->getArrivalTime();
        $price = (string) $booking->getPrice();

        if (null === $departureTime) {
            return ['percentage' => 0, 'amount' => '0.00'];
        }

        $secondsUntilDeparture = $departureTime->getTimestamp() - $this->clock->now()->getTimestamp();
        if ($secondsUntilDeparture <= 0) {
            return ['percentage' => 0, 'amount' => '0.00'];
        }

        if ($this->hasScheduleChangedBeyondTolerance($booking) || $secondsUntilDeparture > self::SECONDS_24_HOURS) {
            return [
                'percentage' => 100,
                'amount' => bcmul($price, '1.00', 2),
            ];
        }

        if ($secondsUntilDeparture >= self::SECONDS_2_HOURS) {
            return [
                'percentage' => 50,
                'amount' => bcmul($price, '0.50', 2),
            ];
        }

        return [
            'percentage' => 0,
            'amount' => '0.00',
        ];
    }

    /**
     * Vérifie à partir des colonnes persistantes (originalDepartureTime / originalArrivalTime)
     * si un arrêt couvert par la réservation a subi un décalage horaire strictement supérieur à ±30 min.
     */
    private function hasScheduleChangedBeyondTolerance(Booking $booking): bool
    {
        $trip = $booking->getTrip();
        $fromOrder = $booking->getFromStop()?->getStopOrder();
        $toOrder = $booking->getToStop()?->getStopOrder();

        if (null === $trip || null === $fromOrder || null === $toOrder) {
            return false;
        }

        foreach ($trip->getStops() as $stop) {
            $order = $stop->getStopOrder();
            if (null === $order || $order < $fromOrder || $order > $toOrder) {
                continue;
            }

            if (null !== $stop->getOriginalDepartureTime() && null !== $stop->getDepartureTime()) {
                $delta = abs($stop->getDepartureTime()->getTimestamp() - $stop->getOriginalDepartureTime()->getTimestamp());
                if ($delta > TripService::SCHEDULE_TOLERANCE_SECONDS) {
                    return true;
                }
            }

            if (null !== $stop->getOriginalArrivalTime() && null !== $stop->getArrivalTime()) {
                $delta = abs($stop->getArrivalTime()->getTimestamp() - $stop->getOriginalArrivalTime()->getTimestamp());
                if ($delta > TripService::SCHEDULE_TOLERANCE_SECONDS) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Annulation du trajet entier par le conducteur :
     * - Le trajet passe à CANCELLED
     * - Toutes les réservations CONFIRMED passent à CANCELLED_BY_DRIVER avec remboursement 100 %.
     */
    public function cancelTripByDriver(Trip $trip, User $driver): Trip
    {
        $tripDriver = $trip->getDriver();
        if (null === $tripDriver || ($tripDriver !== $driver && $tripDriver->getId() !== $driver->getId())) {
            throw new InvalidCancellationException('Seul le conducteur du trajet peut l\'annuler.');
        }

        if (TripStatus::PUBLISHED !== $trip->getStatus()) {
            throw new InvalidCancellationException('Seul un trajet publié peut être annulé.');
        }

        $now = $this->clock->now();

        $this->em->beginTransaction();
        try {
            $this->em->refresh($trip, LockMode::PESSIMISTIC_WRITE);

            if (TripStatus::PUBLISHED !== $trip->getStatus()) {
                throw new InvalidCancellationException('Seul un trajet publié peut être annulé.');
            }

            $trip
                ->setStatus(TripStatus::CANCELLED)
                ->setCancelledAt($now);

            foreach ($trip->getBookings() as $booking) {
                if (BookingStatus::CONFIRMED === $booking->getStatus()) {
                    $booking
                        ->setStatus(BookingStatus::CANCELLED_BY_DRIVER)
                        ->setRefundAmount(bcmul((string) $booking->getPrice(), '1.00', 2))
                        ->setCancelledAt($now);
                }
            }

            $this->em->flush();
            $this->em->commit();

            return $trip;
        } catch (\Throwable $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->rollback();
            }

            throw $e;
        }
    }
}
