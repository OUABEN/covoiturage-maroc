<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Trip;
use App\Entity\TripStop;
use App\Repository\BookingRepository;

class AvailabilityService
{
    public function __construct(
        private readonly BookingRepository $bookingRepository,
    ) {
    }

    /**
     * Calcule les places disponibles sur la portion [$from, $to] d'un trajet :
     * seatsTotal - max_{k in [from.stopOrder, to.stopOrder[} (somme des seats CONFIRMED couvrant k).
     */
    public function availableSeats(Trip $trip, TripStop $from, TripStop $to): int
    {
        if ($from->getTrip() !== $trip || $to->getTrip() !== $trip) {
            throw new \InvalidArgumentException('Les arrêts doivent appartenir au trajet indiqué.');
        }

        $fromOrder = $from->getStopOrder();
        $toOrder = $to->getStopOrder();

        if (null === $fromOrder || null === $toOrder || $fromOrder >= $toOrder) {
            throw new \InvalidArgumentException('L\'ordre de l\'arrêt de départ doit être strictement inférieur à celui de l\'arrêt d\'arrivée.');
        }

        $maxOccupied = $this->bookingRepository->findMaxOccupiedSeatsInRange($trip, $fromOrder, $toOrder);

        return max(0, (int) $trip->getSeatsTotal() - $maxOccupied);
    }
}
