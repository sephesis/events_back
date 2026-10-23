<?php

namespace App\Services\Routing;

/**
 * Проверка последовательности на соответствие временным окнам и расчёт
 * расписания (шаг 5 комбинированного подхода: «проверка полученного маршрута
 * на соответствие временным ограничениям и продолжительности мероприятий»).
 */
class ScheduleEvaluator
{
    /**
     * @param RoutePoint[] $points   все вершины задачи
     * @param int[]        $order    индексы вершин в порядке посещения (без старта)
     * @param int|null     $startIdx индекс стартовой вершины, если она задана
     * @param int|null     $endTime  крайнее время окончания маршрута, мин
     * @return array{feasible: bool, distance: float, finish: float, duration: float, lateness: float, schedule: array}
     */
    public function evaluate(array $points, array $matrix, array $order, ?int $startIdx, ?int $endTime = null, bool $returnToStart = false): array
    {
        $distance = 0.0;
        $lateness = 0.0;
        $schedule = [];
        $prev = $startIdx;
        $time = $startIdx !== null ? (float) $points[$startIdx]->earliest : null;
        $routeStart = $time;

        foreach ($order as $idx) {
            $point = $points[$idx];
            $travelKm = $prev !== null ? $matrix['distance'][$prev][$idx] : 0.0;
            $travelMin = $prev !== null ? $matrix['duration'][$prev][$idx] : 0.0;
            $arrival = $time === null ? (float) $point->earliest : $time + $travelMin;
            $start = max($arrival, (float) $point->earliest);
            $late = max(0.0, $start - $point->latest);
            $finish = $start + $point->service;

            $routeStart ??= $start;
            $distance += $travelKm;
            $lateness += $late;
            $schedule[] = [
                'index' => $idx,
                'distance_from_prev_km' => $prev !== null ? round($travelKm, 3) : null,
                'travel_min' => $prev !== null ? round($travelMin, 1) : null,
                'arrival' => $arrival,
                'start' => $start,
                'wait_min' => round($start - $arrival, 1),
                'late_min' => round($late, 1),
                'finish' => $finish,
            ];

            $time = $finish;
            $prev = $idx;
        }

        if ($returnToStart && $startIdx !== null && $prev !== null && $prev !== $startIdx) {
            $distance += $matrix['distance'][$prev][$startIdx];
            $time += $matrix['duration'][$prev][$startIdx];
        }

        if ($endTime !== null && $time !== null && $time > $endTime) {
            $lateness += $time - $endTime;
        }

        return [
            'feasible' => $lateness <= 0.0,
            'distance' => round($distance, 3),
            'finish' => (float) ($time ?? 0),
            'duration' => round((float) ($time ?? 0) - (float) ($routeStart ?? 0), 1),
            'lateness' => round($lateness, 1),
            'schedule' => $schedule,
        ];
    }
}
