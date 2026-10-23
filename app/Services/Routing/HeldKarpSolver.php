<?php

namespace App\Services\Routing;

/**
 * Динамическое программирование Хелда–Карпа для задачи коммивояжёра
 * с открытым концом, временными окнами и необязательными вершинами.
 *
 * Состояние — (S, j): посещено подмножество S, последняя вершина j.
 * Классический ХК хранит одно число C(S, j) = min стоимость; при временных
 * окнах этого недостаточно: путь короче, но позже по времени, может
 * отрезать продолжения, доступные более длинному, но раннему пути.
 * Поэтому в каждом состоянии хранится Парето-множество меток
 * (расстояние, время окончания) — оба критерия «чем меньше, тем лучше».
 *
 * Сложность по-прежнему O(n²·2ⁿ·L), где L — размер Парето-множества
 * (ограничен LABEL_CAP), поэтому метод применяется до ~12 точек.
 *
 * Необязательность вершин получается бесплатно: среди всех состояний
 * выбирается то, что покрывает больше мероприятий, при равенстве —
 * с меньшим расстоянием. Невместимые по времени точки попадают в skipped.
 */
class HeldKarpSolver
{
    private const LABEL_CAP = 6;

    /**
     * @param RoutePoint[] $points     все вершины
     * @param int[]        $eventIdx   индексы вершин-мероприятий
     * @param int|null     $startIdx   индекс стартовой вершины
     * @return array<int, array{order: int[], distance: float, finish: float}> лучшие решения, по убыванию качества
     */
    public function solve(array $points, array $matrix, array $eventIdx, ?int $startIdx, ?int $endTime, bool $returnToStart, int $maxVariants = 3): array
    {
        $m = count($eventIdx);

        if ($m === 0) {
            return [];
        }

        $dist = $matrix['distance'];
        $dur = $matrix['duration'];
        // labels[mask][j] = list of [distance, finish, prevJ, prevLabelIdx]
        $labels = [];

        for ($j = 0; $j < $m; $j++) {
            $p = $points[$eventIdx[$j]];

            if ($startIdx !== null) {
                $arrival = $points[$startIdx]->earliest + $dur[$startIdx][$eventIdx[$j]];
                $d0 = $dist[$startIdx][$eventIdx[$j]];
            } else {
                $arrival = $p->earliest;
                $d0 = 0.0;
            }

            $start = max($arrival, $p->earliest);

            if ($start > $p->latest) {
                continue;
            }

            $finish = $start + $p->service;

            if ($endTime !== null && $finish > $endTime) {
                continue;
            }

            $labels[1 << $j][$j] = [[$d0, $finish, -1, -1]];
        }

        $full = (1 << $m) - 1;

        // Маски перебираются по возрастанию: любое подмножество меньше надмножества.
        for ($mask = 1; $mask <= $full; $mask++) {
            if (! isset($labels[$mask])) {
                continue;
            }

            foreach ($labels[$mask] as $j => $list) {
                $gj = $eventIdx[$j];

                for ($k = 0; $k < $m; $k++) {
                    if ($mask & (1 << $k)) {
                        continue;
                    }

                    $gk = $eventIdx[$k];
                    $pk = $points[$gk];
                    $next = $mask | (1 << $k);

                    foreach ($list as $li => [$d, $finish]) {
                        $start = max($finish + $dur[$gj][$gk], $pk->earliest);

                        if ($start > $pk->latest) {
                            continue;
                        }

                        $newFinish = $start + $pk->service;

                        if ($endTime !== null && $newFinish > $endTime) {
                            continue;
                        }

                        $this->insertLabel($labels[$next][$k], [$d + $dist[$gj][$gk], $newFinish, $j, $li]);
                    }
                }
            }
        }

        return $this->collectBest($labels, $eventIdx, $points, $matrix, $startIdx, $endTime, $returnToStart, $maxVariants);
    }

    /**
     * Вставка метки с отсечением доминируемых: метка A доминирует B,
     * если A не хуже B ни по расстоянию, ни по времени окончания.
     */
    private function insertLabel(?array &$list, array $label): void
    {
        $list ??= [];

        foreach ($list as $existing) {
            if ($existing[0] <= $label[0] + 1e-9 && $existing[1] <= $label[1] + 1e-9) {
                return;
            }
        }

        $list = array_values(array_filter(
            $list,
            fn (array $e) => ! ($label[0] <= $e[0] + 1e-9 && $label[1] <= $e[1] + 1e-9)
        ));
        $list[] = $label;

        if (count($list) > self::LABEL_CAP) {
            usort($list, fn ($a, $b) => $a[0] <=> $b[0]);
            $list = array_slice($list, 0, self::LABEL_CAP);
        }
    }

    private function collectBest(array $labels, array $eventIdx, array $points, array $matrix, ?int $startIdx, ?int $endTime, bool $returnToStart, int $maxVariants): array
    {
        $candidates = [];

        foreach ($labels as $mask => $byLast) {
            $size = substr_count(decbin($mask), '1');

            foreach ($byLast as $j => $list) {
                foreach ($list as $li => [$d, $finish]) {
                    $total = $d;
                    $end = $finish;

                    if ($returnToStart && $startIdx !== null) {
                        $total += $matrix['distance'][$eventIdx[$j]][$startIdx];
                        $end += $matrix['duration'][$eventIdx[$j]][$startIdx];

                        if ($endTime !== null && $end > $endTime) {
                            continue;
                        }
                    }

                    $candidates[] = [$size, $total, $end, $mask, $j, $li];
                }
            }
        }

        // Больше мероприятий → меньше расстояние → раньше финиш.
        usort($candidates, fn ($a, $b) => [$b[0], $a[1], $a[2]] <=> [$a[0], $b[1], $b[2]]);

        $result = [];
        $seen = [];
        $bestSize = $candidates[0][0] ?? 0;

        foreach ($candidates as [$size, $total, $end, $mask, $j, $li]) {
            if ($size < $bestSize) {
                break; // варианты должны покрывать одинаковый набор по размеру
            }

            $order = $this->reconstruct($labels, $eventIdx, $mask, $j, $li);
            $key = implode('-', $order);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = ['order' => $order, 'distance' => round($total, 3), 'finish' => $end];

            if (count($result) >= $maxVariants) {
                break;
            }
        }

        return $result;
    }

    private function reconstruct(array $labels, array $eventIdx, int $mask, int $j, int $li): array
    {
        $order = [];

        while ($j >= 0) {
            $order[] = $eventIdx[$j];
            [, , $prevJ, $prevLi] = $labels[$mask][$j][$li];
            $mask &= ~(1 << $j);
            $j = $prevJ;
            $li = $prevLi;
        }

        return array_reverse($order);
    }
}
