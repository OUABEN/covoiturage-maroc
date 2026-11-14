<?php

declare(strict_types=1);

namespace App\Tests\Service\Geo;

use App\Entity\City;
use App\Exception\ExternalGeoServiceException;
use App\Service\Geo\NominatimClient;
use App\Service\Geo\OsrmClient;
use App\Service\Geo\RoutePricingService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class GeoServicesTest extends TestCase
{
    public function testNominatimGeocodeSuccessSendsUserAgentAndUsesCache(): void
    {
        $capturedHeaders = [];
        $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedHeaders): MockResponse {
            $capturedHeaders = $options['normalized_headers'] ?? [];

            return new MockResponse(json_encode([
                [
                    'lat' => '30.427755',
                    'lon' => '-9.598107',
                    'display_name' => 'Agadir, Souss-Massa, Maroc',
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        $cache = new ArrayAdapter();
        $clock = new MockClock('2026-11-15 10:00:00');
        $nominatim = new NominatimClient($mockClient, $cache, $clock);

        $first = $nominatim->geocode('Agadir');
        self::assertSame(30.427755, $first['lat']);
        self::assertSame(-9.598107, $first['lon']);
        self::assertSame(1, $mockClient->getRequestsCount());

        // Verify explicit User-Agent header
        $userAgentHeader = implode(' ', $capturedHeaders['user-agent'] ?? []);
        self::assertStringContainsString('covoiturage-maroc-pfe', $userAgentHeader);

        // Second call with same city hits cache without making any HTTP request
        $second = $nominatim->geocode('  agadir ');
        self::assertSame($first, $second);
        self::assertSame(1, $mockClient->getRequestsCount());
    }

    public function testNominatimRateLimitEnforcesMaxOneRequestPerSecond(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse(json_encode([['lat' => '30.42', 'lon' => '-9.59', 'display_name' => 'Agadir']], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([['lat' => '31.62', 'lon' => '-7.98', 'display_name' => 'Marrakech']], JSON_THROW_ON_ERROR)),
        ]);

        $cache = new ArrayAdapter();
        $clock = new MockClock('2026-11-15 10:00:00.000');
        $nominatim = new NominatimClient($mockClient, $cache, $clock);

        $nominatim->geocode('Agadir');

        // Advance only 300ms (< 1 second) and query a different uncached city -> rate limit triggered
        $clock->modify('+300 milliseconds');

        try {
            $nominatim->geocode('Marrakech');
            self::fail('Expected ExternalGeoServiceException due to 1 req/s rate limit.');
        } catch (ExternalGeoServiceException $e) {
            self::assertStringContainsString('Limite de débit Nominatim', $e->getMessage());
        }

        // Advance past 1 full second -> request succeeds
        $clock->modify('+1 second');
        $marrakech = $nominatim->geocode('Marrakech');
        self::assertSame(31.62, $marrakech['lat']);
        self::assertSame(2, $mockClient->getRequestsCount());
    }

    public function testNominatimTimeoutAndInvalidResponseThrowClearException(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('', ['error' => 'Operation timed out after 5000 milliseconds']),
            new MockResponse('[]', ['http_code' => 200]),
        ]);

        $cache = new ArrayAdapter();
        $clock = new MockClock('2026-11-15 10:00:00');
        $nominatim = new NominatimClient($mockClient, $cache, $clock);

        try {
            $nominatim->geocode('Tiznit');
            self::fail('Expected timeout exception');
        } catch (ExternalGeoServiceException $e) {
            self::assertStringContainsString('indisponible', $e->getMessage());
        }

        $clock->modify('+2 seconds');

        $this->expectException(ExternalGeoServiceException::class);
        $this->expectExceptionMessage('Aucun résultat de géocodage valide');
        $nominatim->geocode('VilleInexistanteXYZ');
    }

    public function testOsrmClientCacheAndRoutePricingServiceWithGracefulFallback(): void
    {
        $osrmPayload = [
            'code' => 'Ok',
            'routes' => [
                [
                    'distance' => 245000.0, // 245.0 km
                    'duration' => 10800.0,  // 180 min
                    'geometry' => [
                        'type' => 'LineString',
                        'coordinates' => [[-9.598107, 30.427755], [-7.981084, 31.629472]],
                    ],
                ],
            ],
        ];

        $mockClient = new MockHttpClient([
            new MockResponse(json_encode($osrmPayload, JSON_THROW_ON_ERROR), ['http_code' => 200]),
            new MockResponse([new TransportException('OSRM connection timeout')]),
        ]);

        $cache = new ArrayAdapter();
        $osrm = new OsrmClient($mockClient, $cache);
        $pricing = new RoutePricingService($osrm, 0.60);

        $agadir = (new City())->setName('Agadir')->setLatitude('30.427755')->setLongitude('-9.598107');
        $marrakech = (new City())->setName('Marrakech')->setLatitude('31.629472')->setLongitude('-7.981084');
        $casa = (new City())->setName('Casablanca')->setLatitude('33.573110')->setLongitude('-7.589843');

        // 1. Nominal OSRM call: 245 km * 0.60 MAD/km = 147 MAD => rounded to 5 MAD = 145.00 MAD
        $estimate1 = $pricing->estimateSegment($agadir, $marrakech);
        self::assertFalse($estimate1['degraded']);
        self::assertSame(245.0, $estimate1['distanceKm']);
        self::assertSame(180, $estimate1['durationMinutes']);
        self::assertSame('145.00', $estimate1['suggestedPrice']);
        self::assertSame(1, $mockClient->getRequestsCount());

        // 2. Repeated call for Agadir -> Marrakech uses cache (still 1 HTTP request)
        $estimateCached = $pricing->estimateSegment($agadir, $marrakech);
        self::assertSame($estimate1, $estimateCached);
        self::assertSame(1, $mockClient->getRequestsCount());

        // 3. OSRM timeout on Marrakech -> Casablanca: application continues in degraded fallback mode
        $estimateFallback = $pricing->estimateSegment($marrakech, $casa);
        self::assertTrue($estimateFallback['degraded']);
        self::assertGreaterThan(200.0, $estimateFallback['distanceKm']);
        self::assertNotEmpty($estimateFallback['suggestedPrice']);
        self::assertStringContainsString('OSRM momentanément indisponible', (string) $estimateFallback['message']);
    }
}
