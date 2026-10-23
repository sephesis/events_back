<?php

namespace App\Services\Routing;

/**
 * Приближённое решение для большого числа точек (> exact_max_points):
 *
 *  1. Жадный «ближайший сосед» с учётом временных окон — начальный маршрут;
 *  2. Локальное улучшение 2-opt (разворот участка маршрута) и Or-opt
 *     (перенос одной точки на другую позицию), пока улучшается целевая функция;
 *  3. Вставка не поместившихся точек на лучшую допустимую позицию.
 *
 * Как отмечено в ВКР, эти методы не гарантируют глобальный оптимум,
 * но быстро улучшают результат жадного алгоритма.
 */
class HeuristicSolver
{
    private const MAX_PASSES = 50;

    public function __construct(private readonly ScheduleEvaluator $evaluator)
    {
    }

    /**
     * @return array<int, array{order: int[], distance: float, finish: float}>
     */
    public function solve(array $points, array $matrix, array $eventIdx, ?int $startIdx, ?int $endTime, bool $returnToStart, int $maxVariants = 3): array
    {
        if (empty($eventIdx)) {
            return [];
        }

        // Разные стартовые вершины дают разные локальные оптимумы — из них
        // и набираются альтернативные варианты маршрута.
        $seeds = $startIdx !== null ? [null] : $this->seedCandidates($points, $eventIdx, $maxVariants + 2);
        $solutions = [];

        foreach ($seeds as $seed) {
            $order = $this->nearestNeighbor($points, $matrix, $eventIdx, $startIdx, $endTime, $seed);
            $order = $this->improve($points, $matrix, $order, $startIdx, $endTime, $returnToStart);
            $order = $this->insertMissing($points, $matrix, $order, $eventIdx, $startIdx, $endTime, $returnToStart);
            $eval = $this->evaluator->evaluate($points, $matrix, $order, $startIdx, $endTime, $returnToStart);

            if ($eval['feasible'] && ! empty($order)) {
                $solutions[implode('-', $order)] = ['order' => $order, 'distance' => $eval['distance'], 'finish' => $eval['finish']];
            }
        }

        $solutions = array_values($solutions);
        usort($solutions, fn ($a, $b) => [count($b['order']), $a['distance']] <=> [count($a['order']), $b['distance']]);

        return array_slice($solutions, 0, $maxVariants);
    }

    private function seedCandidates(array $points, array $eventIdx, int $count): array
    {
        $sorted = $eventIdx;
        usort($sorted, fn ($a, $b) => $points[$a]->latest <=> $points[$b]->latest);

        return array_slice($sorted, 0, $count);
    }

    private function nearestNeighbor(array $points, array $matrix, array $eventIdx, ?int $startIdx, ?int $endTime, ?int $seed): array
    {
        $remaining = array_flip($eventIdx);
        $order = [];
        $prev = $startIdx;

        if ($seed !== null) {
            $order[] = $seed;
            unset($remaining[$seed]);
            $prev = $seed;
        }

        while (! empty($remaining)) {
            $best = null;
            $bestDistance = INF;

            foreach (array_keys($remaining) as $candidate) {
                $trial = $this->evaluator->evaluate($points, $matrix, [...$order, $candidate], $startIdx, $endTime);

                if (! $trial['feasible']) {
                    continue;
                }

                $d = $prev !== null ? $matrix['distance'][$prev][$candidate] : 0.0;

                if ($d < $bestDistance) {
                    $bestDistance = $d;
                    $best = $candidate;
                }
            }

            if ($best === null) {
                break;
            }

            $order[] = $best;
            $prev = $best;
            unset($remaining[$best]);
        }

        return $order;
    }

    private function improve(array $points, array $matrix, array $order, ?int $startIdx, ?int $endTime, bool $returnToStart): array
    {
        $n = count($order);
        $best = $this->cost($points, $matrix, $order, $startIdx, $endTime, $returnToStart);

        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $improved = false;

            // 2-opt: разворот участка [i..k].
            for ($i = 0; $i < $n - 1; $i++) {
                for ($k = $i + 1; $k < $n; $k++) {
                    $candidate = array_merge(
                        array_slice($order, 0, $i),
                        array_reverse(array_slice($order, $i, $k - $i + 1)),
                        array_slice($order, $k + 1)
                    );
                    $cost = $this->cost($points, $matrix, $candidate, $startIdx, $endTime, $returnToStart);

                    if ($cost < $best - 1e-6) {
                        [$order, $best, $improved] = [$candidate, $cost, true];
                    }
                }
            }

            // Or-opt: перенос одной точки с позиции i на позицию k.
            for ($i = 0; $i < $n; $i++) {
                for ($k = 0; $k < $n; $k++) {
                    if ($i === $k) {
                        continue;
                    }

                    $candidate = $order;
                    $moved = array_splice($candidate, $i, 1);
                    array_splice($candidate, $k, 0, $moved);
                    $cost = $this->cost($points, $matrix, $candidate, $startIdx, $endTime, $returnToStart);

                    if ($cost < $best - 1e-6) {
                        [$order, $best, $improved] = [$candidate, $cost, true];
                    }
                }
            }

            if (! $improved) {
                break;
            }
        }

        return $order;
    }

    private function insertMissing(array $points, array $matrix, array $order, array $eventIdx, ?int $startIdx, ?int $endTime, bool $returnToStart): array
    {
        foreach (array_diff($eventIdx, $order) as $missing) {
            $bestOrder = null;
            $bestCost = INF;

            for ($pos = 0; $pos <= count($order); $pos++) {
                $candidate = $order;
                array_splice($candidate, $pos, 0, [$missing]);
                $cost = $this->cost($points, $matrix, $candidate, $startIdx, $endTime, $returnToStart);

                if ($cost < $bestCost) {
                    [$bestOrder, $bestCost] = [$candidate, $cost];
                }
            }

            if ($bestOrder !== null && $bestCost < INF) {
                $order = $bestOrder;
            }
        }

        return $order;
    }

    /** Целевая функция: суммарное расстояние; недопустимые по времени — INF. */
    private function cost(array $points, array $matrix, array $order, ?int $startIdx, ?int $endTime, bool $returnToStart): float
    {
        $eval = $this->evaluator->evaluate($points, $matrix, $order, $startIdx, $endTime, $returnToStart);

        return $eval['feasible'] ? $eval['distance'] : INF;
    }
}
