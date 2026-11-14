<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Exception\InvalidCancellationException;
use App\Exception\InvalidReviewException;
use App\Repository\BookingRepository;
use App\Repository\ReviewRepository;
use App\Security\Voter\BookingVoter;
use App\Service\CancellationService;
use App\Service\ReviewService;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class PassengerController extends AbstractController
{
    #[Route('/mes-reservations', name: 'app_my_bookings', methods: ['GET'])]
    public function index(
        BookingRepository $bookingRepository,
        ReviewRepository $reviewRepository,
        CancellationService $cancellationService,
        ClockInterface $clock,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $now = $clock->now();

        $bookings = $bookingRepository->findBy(
            ['passenger' => $user],
            ['createdAt' => 'DESC']
        );

        $upcomingBookings = [];
        $pastBookings = [];
        $cancelledBookings = [];
        $refundPreviews = [];
        $reviewedBookingIds = [];

        foreach ($bookings as $booking) {
            $trip = $booking->getTrip();
            $depTime = $booking->getFromStop()?->getDepartureTime() ?? $booking->getFromStop()?->getArrivalTime();

            if (BookingStatus::CONFIRMED !== $booking->getStatus()) {
                $cancelledBookings[] = $booking;

                continue;
            }

            $isUpcoming = null !== $trip
                && TripStatus::PUBLISHED === $trip->getStatus()
                && null !== $depTime
                && $depTime > $now;

            if ($isUpcoming) {
                $upcomingBookings[] = $booking;
                if (null !== $booking->getId()) {
                    $refundPreviews[$booking->getId()] = $cancellationService->previewPassengerRefund($booking);
                }
            } else {
                $pastBookings[] = $booking;
                if (null !== $booking->getId()) {
                    $existingReview = $reviewRepository->findOneBy([
                        'booking' => $booking,
                        'author' => $user,
                    ]);
                    $reviewedBookingIds[$booking->getId()] = null !== $existingReview;
                }
            }
        }

        return $this->render('passenger/bookings.html.twig', [
            'upcomingBookings' => $upcomingBookings,
            'pastBookings' => $pastBookings,
            'cancelledBookings' => $cancelledBookings,
            'refundPreviews' => $refundPreviews,
            'reviewedBookingIds' => $reviewedBookingIds,
        ]);
    }

    #[Route('/mes-reservations/{id}/annuler', name: 'app_booking_cancel_confirm', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function confirmCancel(
        Booking $booking,
        CancellationService $cancellationService,
    ): Response {
        $this->denyAccessUnlessGranted(BookingVoter::CANCEL, $booking);

        $preview = $cancellationService->previewPassengerRefund($booking);

        return $this->render('passenger/confirm_cancel.html.twig', [
            'booking' => $booking,
            'preview' => $preview,
        ]);
    }

    #[Route('/mes-reservations/{id}/annuler', name: 'app_booking_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(
        Booking $booking,
        Request $request,
        CancellationService $cancellationService,
    ): Response {
        $this->denyAccessUnlessGranted(BookingVoter::CANCEL, $booking);

        if (!$this->isCsrfTokenValid('cancel_booking_'.$booking->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_my_bookings');
        }

        /** @var User $user */
        $user = $this->getUser();

        try {
            $cancellationService->cancelByPassenger($booking, $user);
            $this->addFlash(
                'success',
                sprintf(
                    'Votre réservation #%d a été annulée. Montant remboursé : %s MAD.',
                    $booking->getId(),
                    $booking->getRefundAmount()
                )
            );
        } catch (InvalidCancellationException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_my_bookings');
    }

    #[Route('/mes-reservations/{id}/avis', name: 'app_booking_review', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function review(
        Booking $booking,
        Request $request,
        ReviewService $reviewService,
    ): Response {
        $this->denyAccessUnlessGranted(BookingVoter::REVIEW, $booking);

        if (!$this->isCsrfTokenValid('review_booking_'.$booking->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_my_bookings');
        }

        /** @var User $user */
        $user = $this->getUser();
        $rating = $request->request->getInt('rating', 5);
        $comment = trim((string) $request->request->get('comment', ''));

        try {
            $reviewService->createReview($booking, $user, $rating, '' !== $comment ? $comment : null);
            $this->addFlash('success', 'Merci ! Votre avis a bien été enregistré.');
        } catch (InvalidReviewException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_my_bookings');
    }
}
