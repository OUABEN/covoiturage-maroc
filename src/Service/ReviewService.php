<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Review;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Exception\InvalidReviewException;
use App\Repository\ReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

class ReviewService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReviewRepository $reviewRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Crée un avis croisé (passager -> conducteur ou conducteur -> passager) sur une réservation.
     * Conditions :
     * - Le trajet doit être COMPLETED
     * - La réservation doit être CONFIRMED
     * - L'auteur doit être soit le passager de la réservation, soit le conducteur du trajet (cible = l'autre partie)
     * - Un seul avis par couple (booking, author)
     * - Note comprise entre 1 et 5.
     */
    public function createReview(Booking $booking, User $author, int $rating, ?string $comment = null): Review
    {
        if ($rating < 1 || $rating > 5) {
            throw new InvalidReviewException('La note doit être comprise entre 1 et 5.');
        }

        if (BookingStatus::CONFIRMED !== $booking->getStatus()) {
            throw new InvalidReviewException('Seule une réservation confirmée permet de déposer un avis.');
        }

        $trip = $booking->getTrip();
        if (null === $trip || TripStatus::COMPLETED !== $trip->getStatus()) {
            throw new InvalidReviewException('Impossible de déposer un avis tant que le trajet n\'est pas terminé.');
        }

        $passenger = $booking->getPassenger();
        $driver = $trip->getDriver();

        if (null === $passenger || null === $driver) {
            throw new InvalidReviewException('Les participants de la réservation sont incomplets.');
        }

        if ($this->isSameUser($author, $passenger)) {
            $target = $driver;
        } elseif ($this->isSameUser($author, $driver)) {
            $target = $passenger;
        } else {
            throw new InvalidReviewException('Un utilisateur tiers ne peut pas évaluer cette réservation.');
        }

        $existing = $this->reviewRepository->findOneBy([
            'booking' => $booking,
            'author' => $author,
        ]);

        if (null !== $existing) {
            throw new InvalidReviewException('Vous avez déjà déposé un avis pour cette réservation.');
        }

        $review = (new Review())
            ->setBooking($booking)
            ->setAuthor($author)
            ->setTarget($target)
            ->setRating($rating)
            ->setComment($comment)
            ->setCreatedAt($this->clock->now());

        $this->em->persist($review);
        $this->em->flush();

        return $review;
    }

    private function isSameUser(User $a, User $b): bool
    {
        return $a === $b || (null !== $a->getId() && $a->getId() === $b->getId());
    }
}
