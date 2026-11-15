<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\City;
use App\Repository\CityRepository;
use App\Repository\TripRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class MapTripsController extends AbstractController
{
    private const RATE_LIMIT_MAX_REQUESTS = 60;
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    #[Route('/api/map/trips', name: 'api_map_trips', methods: ['GET'])]
    public function __invoke(
        Request $request,
        CityRepository $cityRepository,
        TripRepository $tripRepository,
        CacheInterface $cache,
    ): JsonResponse {
        // Rate limiting: 60 requests per minute per IP
        $clientIp = (string) ($request->getClientIp() ?? '127.0.0.1');
        $window = (int) (time() / self::RATE_LIMIT_WINDOW_SECONDS);
        $rateLimitKey = sprintf('rate_limit_map_trips_%s_%d', md5($clientIp), $window);

        $requestCount = $cache->get($rateLimitKey, static function (ItemInterface $item): int {
            $item->expiresAfter(self::RATE_LIMIT_WINDOW_SECONDS + 5);

            return 0;
        });

        if ($requestCount >= self::RATE_LIMIT_MAX_REQUESTS) {
            return new JsonResponse([
                'error' => 'Trop de requêtes. Veuillez patienter une minute.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $cache->delete($rateLimitKey);
        $cache->get($rateLimitKey, static function (ItemInterface $item) use ($requestCount): int {
            $item->expiresAfter(self::RATE_LIMIT_WINDOW_SECONDS + 5);

            return $requestCount + 1;
        });

        // 1. Validate "from" (required positive integer)
        $rawFrom = $request->query->get('from');
        if (null === $rawFrom || '' === trim((string) $rawFrom) || !ctype_digit(trim((string) $rawFrom)) || (int) $rawFrom <= 0) {
            return new JsonResponse([
                'error' => "Le paramètre 'from' est requis et doit être un entier positif.",
            ], Response::HTTP_BAD_REQUEST);
        }
        $fromId = (int) $rawFrom;

        // 2. Validate "to" (optional positive integer, distinct from "from")
        $toId = null;
        $rawTo = $request->query->get('to');
        if (null !== $rawTo && '' !== trim((string) $rawTo)) {
            $trimmedTo = trim((string) $rawTo);
            if (!ctype_digit($trimmedTo) || (int) $trimmedTo <= 0 || (int) $trimmedTo === $fromId) {
                return new JsonResponse([
                    'error' => "Le paramètre 'to' doit être un entier positif distinct de la ville de départ.",
                ], Response::HTTP_BAD_REQUEST);
            }
            $toId = (int) $trimmedTo;
        }

        // 3. Validate "date" (optional, format YYYY-MM-DD)
        $searchDate = null;
        $rawDate = $request->query->get('date');
        if (null !== $rawDate && '' !== trim((string) $rawDate)) {
            $trimmedDate = trim((string) $rawDate);
            $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $trimmedDate);
            if (false === $parsedDate || $parsedDate->format('Y-m-d') !== $trimmedDate) {
                return new JsonResponse([
                    'error' => "Format de date invalide (attendu : YYYY-MM-DD).",
                ], Response::HTTP_BAD_REQUEST);
            }
            $searchDate = $parsedDate;
        }

        // 4. Resolve cities from database
        $fromCity = $cityRepository->find($fromId);
        if (!$fromCity instanceof City) {
            return new JsonResponse([
                'error' => 'Ville de départ introuvable.',
            ], Response::HTTP_NOT_FOUND);
        }

        $toCity = null;
        if (null !== $toId) {
            $toCity = $cityRepository->find($toId);
            if (!$toCity instanceof City) {
                return new JsonResponse([
                    'error' => "Ville d'arrivée introuvable.",
                ], Response::HTTP_NOT_FOUND);
            }
        }

        // Query execution
        if ($toCity instanceof City) {
            // Case A: from + to provided
            $searchResults = $tripRepository->search($fromCity, $toCity, $searchDate);
            $tripsData = [];

            foreach ($searchResults as $res) {
                $trip = $res->trip;
                $driver = $trip->getDriver();
                $driverInitial = $driver !== null && '' !== (string) $driver->getLastName()
                    ? mb_strtoupper(mb_substr((string) $driver->getLastName(), 0, 1)).'.'
                    : '';

                $depTime = $res->fromStop->getDepartureTime();
                $arrTime = $res->toStop->getArrivalTime() ?? $res->toStop->getDepartureTime();

                $tripsData[] = [
                    'id' => $trip->getId(),
                    'driver' => [
                        'id' => $driver?->getId(),
                        'firstName' => $driver?->getFirstName(),
                        'lastNameInitial' => $driverInitial,
                    ],
                    'from' => [
                        'id' => $fromCity->getId(),
                        'name' => $fromCity->getName(),
                    ],
                    'to' => [
                        'id' => $toCity->getId(),
                        'name' => $toCity->getName(),
                    ],
                    'departureTime' => $depTime?->format(\DateTimeInterface::ATOM),
                    'departureTimeFormatted' => $depTime?->format('H:i'),
                    'arrivalTime' => $arrTime?->format(\DateTimeInterface::ATOM),
                    'arrivalTimeFormatted' => $arrTime?->format('H:i'),
                    'dateFormatted' => $depTime?->format('d/m/Y'),
                    'portionPrice' => $res->portionPrice,
                    'availableSeats' => $res->availableSeats,
                    'seatsTotal' => $trip->getSeatsTotal(),
                    'stopsCount' => count($trip->getStops()),
                    'detailUrl' => $this->generateUrl('app_trip_show', [
                        'id' => $trip->getId(),
                        'from' => $res->fromStop->getId(),
                        'to' => $res->toStop->getId(),
                    ], UrlGeneratorInterface::ABSOLUTE_PATH),
                ];
            }

            return new JsonResponse([
                'from' => [
                    'id' => $fromCity->getId(),
                    'name' => $fromCity->getName(),
                    'lat' => (float) $fromCity->getLatitude(),
                    'lng' => (float) $fromCity->getLongitude(),
                ],
                'to' => [
                    'id' => $toCity->getId(),
                    'name' => $toCity->getName(),
                    'lat' => (float) $toCity->getLatitude(),
                    'lng' => (float) $toCity->getLongitude(),
                ],
                'date' => $searchDate?->format('Y-m-d'),
                'count' => count($tripsData),
                'trips' => $tripsData,
            ]);
        }

        // Case B: only "from" provided
        $results = $tripRepository->findUpcomingFromCity($fromCity, $searchDate);
        $tripsData = [];

        foreach ($results as $item) {
            $trip = $item['trip'];
            $fromStop = $item['fromStop'];
            $destinations = $item['destinations'];
            $driver = $trip->getDriver();
            $driverInitial = $driver !== null && '' !== (string) $driver->getLastName()
                ? mb_strtoupper(mb_substr((string) $driver->getLastName(), 0, 1)).'.'
                : '';

            $depTime = $fromStop->getDepartureTime();

            $destList = [];
            foreach ($destinations as $d) {
                $stop = $d['stop'];
                $city = $stop->getCity();
                $arr = $stop->getArrivalTime() ?? $stop->getDepartureTime();
                $destList[] = [
                    'cityId' => $city?->getId(),
                    'cityName' => $city?->getName(),
                    'arrivalTimeFormatted' => $arr?->format('H:i'),
                    'portionPrice' => $d['portionPrice'],
                    'availableSeats' => $d['availableSeats'],
                    'detailUrl' => $this->generateUrl('app_trip_show', [
                        'id' => $trip->getId(),
                        'from' => $fromStop->getId(),
                        'to' => $stop->getId(),
                    ], UrlGeneratorInterface::ABSOLUTE_PATH),
                ];
            }

            $lastDest = end($destList);

            $tripsData[] = [
                'id' => $trip->getId(),
                'driver' => [
                    'id' => $driver?->getId(),
                    'firstName' => $driver?->getFirstName(),
                    'lastNameInitial' => $driverInitial,
                ],
                'from' => [
                    'id' => $fromCity->getId(),
                    'name' => $fromCity->getName(),
                ],
                'departureTime' => $depTime?->format(\DateTimeInterface::ATOM),
                'departureTimeFormatted' => $depTime?->format('H:i'),
                'dateFormatted' => $depTime?->format('d/m/Y'),
                'portionPrice' => $destList[0]['portionPrice'] ?? '0.00',
                'availableSeats' => $destList[0]['availableSeats'] ?? $trip->getSeatsTotal(),
                'seatsTotal' => $trip->getSeatsTotal(),
                'stopsCount' => count($trip->getStops()),
                'finalDestination' => $lastDest['cityName'] ?? '',
                'destinations' => $destList,
                'detailUrl' => $this->generateUrl('app_trip_show', [
                    'id' => $trip->getId(),
                ], UrlGeneratorInterface::ABSOLUTE_PATH),
            ];
        }

        return new JsonResponse([
            'from' => [
                'id' => $fromCity->getId(),
                'name' => $fromCity->getName(),
                'lat' => (float) $fromCity->getLatitude(),
                'lng' => (float) $fromCity->getLongitude(),
            ],
            'to' => null,
            'date' => $searchDate?->format('Y-m-d'),
            'count' => count($tripsData),
            'trips' => $tripsData,
        ]);
    }
}
