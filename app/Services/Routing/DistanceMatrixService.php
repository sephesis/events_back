<?php

namespace App\Services\Routing;

use App\Utils\Geo;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Матрица затрат c_ij между всеми парами точек (шаг 3 комбинированного
 * подхода из ВКР): пешее расстояние (км) и время (мин).
 *
 * Основной источник — OSRM /table (пешеходный профиль OSM): он сам решает
 * задачу кратчайшего пути в графе улиц (Дейкстра с contraction hierarchies),
 * поэтому собственный граф города держать не нужно. При недоступности
 * сервиса используется haversine с поправкой на извилистость улиц.
 */
class DistanceMatrixService
{
    /**
     * @param array<int, array{0: float, 1: float}> $coords [[lat, lng], ...]
     * @return array{distance: array<int, array<int, float>>, duration: array<int, array<int, float>>, source: string}
     */
    public function build(array $coords): array
    {
        $n = count($coords);

        if ($n < 2) {
            return ['distance' => [[0.0]], 'duration' => [[0.0]], 'source' => 'trivial'];
        }

        if (config('recommender.routing.matrix_provider') === 'osrm') {
            $osrm = $this->fromOsrm($coords);

            if ($osrm !== null) {
                return $osrm;
            }
        }

        return $this->fromHaversine($coords);
    }

    public function fromHaversine(array $coords): array
    {
        $detour = (float) config('recommender.routing.detour_factor', 1.3);
        $speed = (float) config('recommender.routing.walk_speed_kmh', 4.5);
        $n = count($coords);
        $distance = [];
        $duration = [];

        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $km = $i === $j ? 0.0 : Geo::distanceKm($coords[$i][0], $coords[$i][1], $coords[$j][0], $coords[$j][1]) * $detour;
                $distance[$i][$j] = round($km, 3);
                $duration[$i][$j] = round($km / $speed * 60, 1);
            }
        }

        return ['distance' => $distance, 'duration' => $duration, 'source' => 'haversine'];
    }

    private function fromOsrm(array $coords): ?array
    {
        $path = implode(';', array_map(
            fn (array $c) => round($c[1], 6) . ',' . round($c[0], 6),
            $coords
        ));
        $cacheKey = 'osrm_table:' . md5($path);

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        // Не дёргаем лежащий сервис на каждом запросе.
        if (Cache::get('osrm_table:down')) {
            return null;
        }

        try {
            $client = new Client(['timeout' => (float) config('recommender.routing.osrm_timeout', 5.0)]);
            $response = $client->get(config('recommender.routing.osrm_table_url') . $path, [
                'query' => ['annotations' => 'distance,duration'],
            ]);
            $data = json_decode((string) $response->getBody(), true);
        } catch (\Throwable $e) {
            Log::warning('OSRM table request failed: ' . $e->getMessage());
            Cache::put('osrm_table:down', true, 300);

            return null;
        }

        if (($data['code'] ?? null) !== 'Ok' || empty($data['distances']) || empty($data['durations'])) {
            return null;
        }

        $fallback = $this->fromHaversine($coords);
        $distance = [];
        $duration = [];

        foreach ($data['distances'] as $i => $row) {
            foreach ($row as $j => $meters) {
                // null — точку не удалось привязать к графу; берём оценку по прямой.
                $distance[$i][$j] = $meters === null ? $fallback['distance'][$i][$j] : round($meters / 1000, 3);
                $seconds = $data['durations'][$i][$j] ?? null;
                $duration[$i][$j] = $seconds === null ? $fallback['duration'][$i][$j] : round($seconds / 60, 1);
            }
        }

        $result = ['distance' => $distance, 'duration' => $duration, 'source' => 'osrm'];
        Cache::put($cacheKey, $result, now()->addDay());

        return $result;
    }
}
