<?php

declare(strict_types=1);

namespace App\Service\Geo;

use App\Exception\ExternalGeoServiceException;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class NominatimClient
{
    public const DEFAULT_USER_AGENT = 'covoiturage-maroc-pfe (contact: contact@covoiturage-maroc.ma)';
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';
    private const CACHE_TTL = 86400;

    private ?float $lastRequestAt = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly ClockInterface $clock,
        private readonly string $geoUserAgent = self::DEFAULT_USER_AGENT,
    ) {
    }

    /**
     * Geocode a Moroccan city or place name via Nominatim with:
     * - explicit User-Agent header
     * - cache.app caching (24h)
     * - strict 1 req/second rate limiting on outgoing HTTP requests
     * - 5s timeout and clear error handling
     *
     * @return array{lat: float, lon: float, displayName: string}
     */
    public function geocode(string $query): array
    {
        $normalized = mb_strtolower(trim($query));
        if ('' === $normalized) {
            throw new ExternalGeoServiceException('La requête de géocodage ne peut pas être vide.');
        }

        $cacheKey = 'nominatim_geocode_'.md5($normalized);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($query): array {
            $item->expiresAfter(self::CACHE_TTL);

            $this->enforceRateLimit();

            try {
                $response = $this->httpClient->request('GET', self::ENDPOINT, [
                    'query' => [
                        'q' => $query.', Maroc',
                        'format' => 'json',
                        'limit' => 1,
                        'countrycodes' => 'ma',
                    ],
                    'headers' => [
                        'User-Agent' => $this->geoUserAgent,
                        'Accept' => 'application/json',
                    ],
                    'timeout' => 5.0,
                ]);

                $data = $response->toArray();
            } catch (ExceptionInterface $e) {
                throw new ExternalGeoServiceException(
                    'Service de géocodage Nominatim momentanément indisponible.',
                    0,
                    $e
                );
            }

            if (!\is_array($data) || [] === $data || !isset($data[0]['lat'], $data[0]['lon'])) {
                throw new ExternalGeoServiceException(
                    sprintf('Aucun résultat de géocodage valide trouvé pour "%s".', $query)
                );
            }

            return [
                'lat' => (float) $data[0]['lat'],
                'lon' => (float) $data[0]['lon'],
                'displayName' => (string) ($data[0]['display_name'] ?? $query),
            ];
        });
    }

    private function enforceRateLimit(): void
    {
        $now = (float) $this->clock->now()->format('U.u');

        if (null !== $this->lastRequestAt && ($now - $this->lastRequestAt) < 1.0) {
            throw new ExternalGeoServiceException(
                'Limite de débit Nominatim atteinte (maximum 1 requête par seconde).'
            );
        }

        $this->lastRequestAt = $now;
    }
}
