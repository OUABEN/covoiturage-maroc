<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Exception\InvalidBookingException;
use App\Exception\NotEnoughSeatsException;
use App\Repository\ReviewRepository;
use App\Repository\TripStopRepository;
use App\Service\AvailabilityService;
use App\Service\BookingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class TripController extends AbstractController
{
    #[Route('/trajets/{id}', name: 'app_trip_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        Trip $trip,
        Request $request,
        AvailabilityService $availabilityService,
        BookingService $bookingService,
        ReviewRepository $reviewRepository,
    ): Response {
        $stops = $trip->getStops()->toArray();
        $selectedFromId = $request->query->getInt('from', $stops[0]->getId() ?? 0);
        $selectedToId = $request->query->getInt('to', $stops[count($stops) - 1]->getId() ?? 0);

        $selectedFrom = $stops[0] ?? null;
        $selectedTo = $stops[count($stops) - 1] ?? null;

        foreach ($stops as $stop) {
            if ($stop->getId() === $selectedFromId) {
                $selectedFrom = $stop;
            }
            if ($stop->getId() === $selectedToId) {
                $selectedTo = $stop;
            }
        }

        if (null !== $selectedFrom && null !== $selectedTo && $selectedFrom->getStopOrder() >= $selectedTo->getStopOrder()) {
            $selectedFrom = $stops[0] ?? null;
            $selectedTo = $stops[count($stops) - 1] ?? null;
        }

        $portionAvailableSeats = 0;
        $portionUnitPrice = '0.00';
        if (null !== $selectedFrom && null !== $selectedTo && $selectedFrom->getStopOrder() < $selectedTo->getStopOrder()) {
            $portionAvailableSeats = $availabilityService->availableSeats($trip, $selectedFrom, $selectedTo);
            $portionUnitPrice = $bookingService->calculatePortionPrice(
                $trip,
                (int) $selectedFrom->getStopOrder(),
                (int) $selectedTo->getStopOrder(),
                1
            );
        }

        // Disponibilité sur chaque segment élémentaire [k, k+1]
        $segmentAvailabilities = [];
        for ($i = 0; $i < count($stops) - 1; ++$i) {
            $segmentAvailabilities[$i] = $availabilityService->availableSeats($trip, $stops[$i], $stops[$i + 1]);
        }

        $driverRating = null !== $trip->getDriver()
            ? $reviewRepository->getRatingSummaryForUser($trip->getDriver())
            : ['average' => null, 'count' => 0];

        return $this->render('trip/show.html.twig', [
            'trip' => $trip,
            'stops' => $stops,
            'selectedFrom' => $selectedFrom,
            'selectedTo' => $selectedTo,
            'portionAvailableSeats' => $portionAvailableSeats,
            'portionUnitPrice' => $portionUnitPrice,
            'segmentAvailabilities' => $segmentAvailabilities,
            'driverRating' => $driverRating,
        ]);
    }

    #[Route('/trajets/{id}/reserver', name: 'app_trip_book', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function book(
        Trip $trip,
        Request $request,
        TripStopRepository $tripStopRepository,
        BookingService $bookingService,
    ): Response {
        if (!$this->isCsrfTokenValid('book_trip_'.$trip->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité CSRF invalide. Veuillez réessayer.');

            return $this->redirectToRoute('app_trip_show', ['id' => $trip->getId()]);
        }

        $fromStopId = $request->request->getInt('from_stop');
        $toStopId = $request->request->getInt('to_stop');
        $seats = $request->request->getInt('seats', 1);

        /** @var TripStop|null $fromStop */
        $fromStop = $tripStopRepository->find($fromStopId);
        /** @var TripStop|null $toStop */
        $toStop = $tripStopRepository->find($toStopId);

        if (null === $fromStop || null === $toStop) {
            $this->addFlash('danger', 'Veuillez sélectionner une étape de départ et une étape d\'arrivée valides.');

            return $this->redirectToRoute('app_trip_show', ['id' => $trip->getId()]);
        }

        /** @var User $passenger */
        $passenger = $this->getUser();

        try {
            $booking = $bookingService->book($trip, $passenger, $fromStop, $toStop, $seats);

            $this->addFlash(
                'success',
                sprintf(
                    'Réservation confirmée pour %d place(s) de %s à %s (Total : %s MAD).',
                    $booking->getSeats(),
                    $fromStop->getCity()?->getName(),
                    $toStop->getCity()?->getName(),
                    $booking->getPrice()
                )
            );
        } catch (NotEnoughSeatsException|InvalidBookingException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_trip_show', [
            'id' => $trip->getId(),
            'from' => $fromStop->getId(),
            'to' => $toStop->getId(),
        ]);
    }
}
