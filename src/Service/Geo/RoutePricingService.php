<?php

declare(strict_types=1);

namespace App\Service\Geo;

use App\Entity\City;
use App\Exception\ExternalGeoServiceException;

class RoutePricingService
{
    public function __construct(
        private readonly OsrmClient $osrmClient,
        private readonly float $ratePerKm = 0.60,
    ) {
    }

    /**
     * Calculate suggested segment price in MAD:
     * distance (km) * ratePerKm (default 0.60 MAD/km), rounded to the nearest 5 MAD (minimum 5.00 MAD).
     */
    public function calculateSuggestedPrice(float $distanceKm): string
    {
        if ($distanceKm <= 0.0) {
            return '5.00';
        }

        $raw = $distanceKm * $this->ratePerKm;
        $roundedToFive = max(5.0, round($raw / 5.0) * 5.0);

        return number_format($roundedToFive, 2, '.', '');
    }

    /**
     * Estimate route distance, duration, GeoJSON geometry and suggested price between two cities.
     * If OSRM is unavailable, gracefully falls back to Haversine road estimate so the app never breaks.
     *
     * @return array{
     *     distanceKm: float,
     *     durationMinutes: int,
     *     geometry: array<string, mixed>,
     *     suggestedPrice: string,
     *     degraded: bool,
     *     message: ?string
     * }
     */
    public function estimateSegment(City $from, City $to): array
    {
        $lat1 = (float) $from->getLatitude();
        $lon1 = (float) $from->getLongitude();
        $lat2 = (float) $to->getLatitude();
        $lon2 = (float) $to->getLongitude();

        try {
            $route = $this->osrmClient->getRoute($lat1, $lon1, $lat2, $lon2);

            return [
                'distanceKm' => $route['distanceKm'],
                'durationMinutes' => $route['durationMinutes'],
                'geometry' => $route['geometry'],
                'suggestedPrice' => $this->calculateSuggestedPrice($route['distanceKm']),
                'degraded' => false,
                'message' => null,
            ];
        } catch (ExternalGeoServiceException $e) {
            $fallbackKm = $this->estimateFallbackRoadDistanceKm($lat1, $lon1, $lat2, $lon2);
            $fallbackMinutes = max(10, (int) round(($fallbackKm / 85.0) * 60.0));

            return [
                'distanceKm' => $fallbackKm,
                'durationMinutes' => $fallbackMinutes,
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => [
                        [$lon1, $lat1],
                        [$lon2, $lat2],
                    ],
                ],
                'suggestedPrice' => $this->calculateSuggestedPrice($fallbackKm),
                'degraded' => true,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function estimateFallbackRoadDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * (sin($dLon / 2) ** 2);
        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));

        // Multiply straight-line distance by 1.22 road factor
        return round($earthRadiusKm * $c * 1.22, 1);
    }
}
