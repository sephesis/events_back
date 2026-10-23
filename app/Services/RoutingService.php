<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Fetches a real, street-following walking route geometry from OSRM
 * (Open Source Routing Machine's public demo server). Used purely for the
 * visual line on the map — RouteBuilder's own distance/ordering math stays
 * on straight-line (haversine) calculations.
 *
 * We proxy this server-side (rather than call it from the browser) so the
 * frontend only ever talks JSON to our own API — no third-party script
 * loading, no browser-specific quirks.
 */
class RoutingService
{
    // router.project-osrm.org's public demo ignores the profile segment and
    // always routes as a car — it silently returns the same distance for
    // "foot", "driving", or a made-up profile name. routing.openstreetmap.de
    // (run by FOSSGIS e.V. for the OSM community) hosts genuinely separate
    // profile instances, so "routed-foot" actually uses pedestrian paths.
    private const BASE_URL = 'https://routing.openstreetmap.de/routed-foot/route/v1/foot/';

    /**
     * @param array<int, array{lat: float, lng: float}> $points Ordered stops.
     * @return array{polyline: array<int, array{0: float, 1: float}>, distance_km: float, duration_min: float}|null
     */
    public function getWalkingRoute(array $points): ?array
    {
        if (count($points) < 2) {
            return null;
        }

        $coordinates = implode(';', array_map(
            fn (array $p) => $p['lng'] . ',' . $p['lat'],
            $points
        ));

        try {
            $client = new Client(['timeout' => 6.0]);
            $response = $client->get(self::BASE_URL . $coordinates, [
                'query' => [
                    'overview' => 'full',
                    'geometries' => 'geojson',
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
        } catch (\Throwable $e) {
            Log::warning('OSRM routing request failed: ' . $e->getMessage());

            return null;
        }

        $route = $data['routes'][0] ?? null;
        $coords = $route['geometry']['coordinates'] ?? null;

        if (! $route || ! is_array($coords) || count($coords) < 2) {
            return null;
        }

        return [
            // OSRM returns [lon, lat]; flip to our [lat, lng] convention.
            'polyline' => array_map(fn ($c) => [$c[1], $c[0]], $coords),
            'distance_km' => round(($route['distance'] ?? 0) / 1000, 3),
            'duration_min' => round(($route['duration'] ?? 0) / 60, 1),
        ];
    }
}
