<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\City;
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
        $results = [];
        $driverRatings = [];

        if ($isSearched) {
            $results = $tripRepository->search($fromCity, $toCity, $searchDate);
            foreach ($results as $result) {
                $driver = $result->trip->getDriver();
                if (null !== $driver && null !== $driver->getId() && !isset($driverRatings[$driver->getId()])) {
                    $driverRatings[$driver->getId()] = $reviewRepository->getRatingSummaryForUser($driver);
                }
            }
        }

        return $this->render('home/index.html.twig', [
            'cities' => $cities,
            'selectedFrom' => $fromCity,
            'selectedTo' => $toCity,
            'selectedDate' => '' !== $dateStr ? $dateStr : (new \DateTimeImmutable('+2 days'))->format('Y-m-d'),
            'isSearched' => $isSearched,
            'results' => $results,
            'driverRatings' => $driverRatings,
        ]);
    }
}
