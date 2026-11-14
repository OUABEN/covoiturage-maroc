<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\City;
use App\Repository\CityRepository;
use App\Service\Geo\RoutePricingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RouteController extends AbstractController
{
    #[Route('/api/route', name: 'api_route_estimate', methods: ['GET'])]
    public function estimate(
        Request $request,
        CityRepository $cityRepository,
        RoutePricingService $routePricingService,
    ): JsonResponse {
        $fromId = $request->query->getInt('from');
        $toId = $request->query->getInt('to');

        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
            return $this->json([
                'error' => 'Veuillez fournir deux identifiants de villes distincts (?from=ID&to=ID).',
            ], Response::HTTP_BAD_REQUEST);
        }

        $fromCity = $cityRepository->find($fromId);
        $toCity = $cityRepository->find($toId);

        if (!$fromCity instanceof City || !$toCity instanceof City) {
            return $this->json([
                'error' => 'Ville de départ ou d\'arrivée introuvable.',
            ], Response::HTTP_NOT_FOUND);
        }

        $estimate = $routePricingService->estimateSegment($fromCity, $toCity);

        return $this->json([
            'from' => [
                'id' => $fromCity->getId(),
                'name' => $fromCity->getName(),
                'lat' => (float) $fromCity->getLatitude(),
                'lon' => (float) $fromCity->getLongitude(),
            ],
            'to' => [
                'id' => $toCity->getId(),
                'name' => $toCity->getName(),
                'lat' => (float) $toCity->getLatitude(),
                'lon' => (float) $toCity->getLongitude(),
            ],
            'distanceKm' => $estimate['distanceKm'],
            'durationMinutes' => $estimate['durationMinutes'],
            'geometry' => $estimate['geometry'],
            'suggestedPrice' => $estimate['suggestedPrice'],
            'degraded' => $estimate['degraded'],
            'message' => $estimate['message'],
        ]);
    }
}
