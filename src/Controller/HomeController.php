<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\TripSearchResult;
use App\Entity\City;
use App\Enum\TripStatus;
use App\Repository\CityRepository;
use App\Repository\ReviewRepository;
use App\Repository\TripRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    #[Route('/recherche', name: 'app_search', methods: ['GET'])]
    public function index(
        Request $request,
        CityRepository $cityRepository,
        TripRepository $tripRepository,
        ReviewRepository $reviewRepository,
    ): Response {
        $cities = $cityRepository->findBy([], ['name' => 'ASC']);

        $fromId = $request->query->getInt('from');
        $toId = $request->query->getInt('to');
        $dateStr = trim((string) $request->query->get('date', ''));
        $sort = trim((string) $request->query->get('sort', 'departure_asc'));

        $fromCity = $fromId > 0 ? $cityRepository->find($fromId) : null;
        $toCity = $toId > 0 ? $cityRepository->find($toId) : null;
        $searchDate = null;

        if ('' !== $dateStr) {
            try {
                $searchDate = new \DateTimeImmutable($dateStr);
            } catch (\Exception) {
                $searchDate = null;
            }
        }

        $isSearched = $fromCity instanceof City && $toCity instanceof City && null !== $searchDate;
        /** @var list<TripSearchResult> $results */
        $results = [];
        $driverRatings = [];

        if ($isSearched) {
            $results = $tripRepository->search($fromCity, $toCity, $searchDate);

            if ('price_asc' === $sort) {
                usort($results, static fn (TripSearchResult $a, TripSearchResult $b): int => (float) $a->portionPrice <=> (float) $b->portionPrice);
            } elseif ('seats_desc' === $sort) {
                usort($results, static fn (TripSearchResult $a, TripSearchResult $b): int => $b->availableSeats <=> $a->availableSeats);
            }

            foreach ($results as $result) {
                $driver = $result->trip->getDriver();
                if (null !== $driver && null !== $driver->getId() && !isset($driverRatings[$driver->getId()])) {
                    $driverRatings[$driver->getId()] = $reviewRepository->getRatingSummaryForUser($driver);
                }
            }
        }

        $popularTrips = $tripRepository->findBy(
            ['status' => TripStatus::PUBLISHED],
            ['createdAt' => 'DESC'],
            3
        );

        return $this->render('home/index.html.twig', [
            'cities' => $cities,
            'selectedFrom' => $fromCity,
            'selectedTo' => $toCity,
            'selectedDate' => '' !== $dateStr ? $dateStr : (new \DateTimeImmutable('+2 days'))->format('Y-m-d'),
            'selectedSort' => $sort,
            'isSearched' => $isSearched,
            'results' => $results,
            'driverRatings' => $driverRatings,
            'popularTrips' => $popularTrips,
        ]);
    }
}
