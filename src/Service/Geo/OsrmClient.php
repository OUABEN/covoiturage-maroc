<?php

declare(strict_types=1);

namespace App\Service\Geo;

use App\Exception\ExternalGeoServiceException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OsrmClient
{
    private const BASE_URL = 'https://router.project-osrm.org/route/v1/driving';
    private const CACHE_TTL = 86400;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly string $geoUserAgent = NominatimClient::DEFAULT_USER_AGENT,
    ) {
    }

    /**
     * Fetch driving route between two coordinates via OSRM public API with:
     * - explicit User-Agent
     * - cache.app caching
     * - 5s timeout and graceful exception translation
     *
     * @return array{distanceKm: float, durationMinutes: int, geometry: array<string, mixed>}
     */
    public function getRoute(float $fromLat, float $fromLon, float $toLat, float $toLon): array
    {
        $coordKey = sprintf('%.5f_%.5f_%.5f_%.5f', $fromLat, $fromLon, $toLat, $toLon);
        $cacheKey = 'osrm_route_'.md5($coordKey);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($fromLat, $fromLon, $toLat, $toLon): array {
            $item->expiresAfter(self::CACHE_TTL);

            $url = sprintf(
                '%s/%.6f,%.6f;%.6f,%.6f',
                self::BASE_URL,
                $fromLon,
                $fromLat,
                $toLon,
                $toLat
            );

            try {
                $response = $this->httpClient->request('GET', $url, [
                    'query' => [
                        'overview' => 'full',
                        'geometries' => 'geojson',
                    ],
                    'headers' => [
                        'User-Agent' => $this->geoUserAgent,
                        'Accept' => 'application/json',
                    ],
                    'timeout' => 5.0,
                ]);

                $payload = $response->toArray();
            } catch (ExceptionInterface $e) {
                throw new ExternalGeoServiceException(
                    'Service d\'itinéraire OSRM momentanément indisponible.',
                    0,
                    $e
                );
            }

            if (
                !\is_array($payload)
                || ($payload['code'] ?? '') !== 'Ok'
                || empty($payload['routes'][0])
                || !isset($payload['routes'][0]['distance'], $payload['routes'][0]['duration'])
            ) {
                throw new ExternalGeoServiceException('Réponse d\'itinéraire OSRM invalide ou incomplète.');
            }

            $route = $payload['routes'][0];
            $distanceKm = round(((float) $route['distance']) / 1000.0, 1);
            $durationMinutes = (int) round(((float) $route['duration']) / 60.0);
            /** @var array<string, mixed> $geometry */
            $geometry = \is_array($route['geometry'] ?? null) ? $route['geometry'] : [
                'type' => 'LineString',
                'coordinates' => [
                    [$fromLon, $fromLat],
                    [$toLon, $toLat],
                ],
            ];

            return [
                'distanceKm' => $distanceKm,
                'durationMinutes' => $durationMinutes,
                'geometry' => $geometry,
            ];
        });
    }
}
