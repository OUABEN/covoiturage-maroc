<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Exception\InvalidTripOperationException;
use Doctrine\ORM\EntityManagerInterface;

class TripService
{
    public const SCHEDULE_TOLERANCE_SECONDS = 1_800; // ±30 minutes

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Met à jour les horaires d'un arrêt avec vérification de cohérence chronologique.
     * Retourne true si le décalage dépasse la tolérance de ±30 minutes (ce qui ouvre droit
     * à une annulation avec remboursement 100 % pour les passagers CONFIRMED quel que soit le délai).
     */
    public function updateStopTime(
        TripStop $stop,
        User $driver,
        ?\DateTimeImmutable $newArrivalTime,
        ?\DateTimeImmutable $newDepartureTime,
    ): bool {
        $trip = $stop->getTrip();
        if (null === $trip) {
            throw new InvalidTripOperationException('Arrêt non rattaché à un trajet.');
        }

        $this->assertDriverAndPublished($trip, $driver);

        if (null !== $newArrivalTime && null !== $newDepartureTime && $newArrivalTime > $newDepartureTime) {
            throw new InvalidTripOperationException('L\'heure d\'arrivée à une étape ne peut pas être postérieure à son heure de départ.');
        }

        $effectiveNewTime = $newDepartureTime ?? $newArrivalTime;
        if (null === $effectiveNewTime) {
            throw new InvalidTripOperationException('Au moins un horaire (arrivée ou départ) doit être renseigné.');
        }

        // Vérification de la chronologie avec les étapes adjacentes
        $stopOrder = $stop->getStopOrder();
        foreach ($trip->getStops() as $otherStop) {
            if ($otherStop === $stop) {
                continue;
            }
            $otherOrder = $otherStop->getStopOrder();
            $otherTime = $otherStop->getDepartureTime() ?? $otherStop->getArrivalTime();
            if (null === $otherOrder || null === $otherTime) {
                continue;
            }

            if ($otherOrder < $stopOrder && ($newArrivalTime ?? $newDepartureTime) <= $otherTime) {
                throw new InvalidTripOperationException('Le nouvel horaire doit être postérieur à l\'étape précédente.');
            }

            $otherArrival = $otherStop->getArrivalTime() ?? $otherStop->getDepartureTime();
            if ($otherOrder > $stopOrder && null !== $otherArrival && ($newDepartureTime ?? $newArrivalTime) >= $otherArrival) {
                throw new InvalidTripOperationException('Le nouvel horaire doit être antérieur à l\'étape suivante.');
            }
        }

        $maxDeltaSeconds = 0;
        $refArrival = $stop->getOriginalArrivalTime() ?? $stop->getArrivalTime();
        if (null !== $refArrival && null !== $newArrivalTime) {
            $maxDeltaSeconds = max($maxDeltaSeconds, abs($newArrivalTime->getTimestamp() - $refArrival->getTimestamp()));
        }
        $refDeparture = $stop->getOriginalDepartureTime() ?? $stop->getDepartureTime();
        if (null !== $refDeparture && null !== $newDepartureTime) {
            $maxDeltaSeconds = max($maxDeltaSeconds, abs($newDepartureTime->getTimestamp() - $refDeparture->getTimestamp()));
        }

        $stop
            ->setArrivalTime($newArrivalTime)
            ->setDepartureTime($newDepartureTime);

        $this->em->persist($stop);
        $this->em->flush();

        return $maxDeltaSeconds > self::SCHEDULE_TOLERANCE_SECONDS;
    }

    /**
     * Supprime une étape d'un trajet publié uniquement si :
     * - Le trajet conserve au moins 2 étapes
     * - Aucune réservation CONFIRMED n'utilise ni ne traverse cet arrêt (from.stopOrder <= stop.stopOrder <= to.stopOrder).
     *
     * Effectue la suppression puis la réindexation en deux phases (deux flush dans une même transaction)
     * car Doctrine exécute les UPDATE avant les DELETE lors d'un flush unique, ce qui violerait
     * la contrainte d'unicité uniq_trip_stop_trip_order (trip_id, stop_order) lors de la suppression d'un arrêt intermédiaire.
     */
    public function removeStop(TripStop $stop, User $driver): void
    {
        $trip = $stop->getTrip();
        if (null === $trip) {
            throw new InvalidTripOperationException('Arrêt non rattaché à un trajet.');
        }

        $this->assertDriverAndPublished($trip, $driver);

        if ($trip->getStops()->count() <= 2) {
            throw new InvalidTripOperationException('Un trajet doit conserver au moins deux étapes (départ et arrivée).');
        }

        $targetOrder = $stop->getStopOrder();
        foreach ($trip->getBookings() as $booking) {
            if (BookingStatus::CONFIRMED !== $booking->getStatus()) {
                continue;
            }

            $fromOrder = $booking->getFromStop()?->getStopOrder();
            $toOrder = $booking->getToStop()?->getStopOrder();

            if (null !== $fromOrder && null !== $toOrder && $fromOrder <= $targetOrder && $targetOrder <= $toOrder) {
                throw new InvalidTripOperationException('Impossible de supprimer un arrêt utilisé ou traversé par une réservation confirmée.');
            }
        }

        $this->em->beginTransaction();
        try {
            // Phase 1 : suppression SQL de l'arrêt pour libérer son stop_order dans l'index unique
            $trip->removeStop($stop);
            $this->em->remove($stop);
            $this->em->flush();

            // Phase 2 : réindexation séquentielle des stopOrder restants (0, 1, 2...)
            $remainingStops = $trip->getStops()->toArray();
            usort($remainingStops, static fn (TripStop $a, TripStop $b): int => (int) $a->getStopOrder() <=> (int) $b->getStopOrder());
            foreach (array_values($remainingStops) as $newIndex => $remainingStop) {
                $remainingStop->setStopOrder($newIndex);
            }

            $this->em->flush();
            $this->em->commit();
        } catch (\Throwable $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->rollback();
            }

            throw $e;
        }
    }

    private function assertDriverAndPublished(Trip $trip, User $driver): void
    {
        $tripDriver = $trip->getDriver();
        if (null === $tripDriver || ($tripDriver !== $driver && $tripDriver->getId() !== $driver->getId())) {
            throw new InvalidTripOperationException('Seul le conducteur du trajet peut modifier ses étapes.');
        }

        if (TripStatus::PUBLISHED !== $trip->getStatus()) {
            throw new InvalidTripOperationException('Seul un trajet publié peut être modifié.');
        }
    }
}
