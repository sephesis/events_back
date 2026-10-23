<?php

namespace Tests\Unit;

use App\Services\Routing\DistanceMatrixService;
use App\Services\Routing\HeldKarpSolver;
use App\Services\Routing\HeuristicSolver;
use App\Services\Routing\RoutePoint;
use App\Services\Routing\ScheduleEvaluator;
use Tests\TestCase;

class RouteSolversTest extends TestCase
{
    private ScheduleEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new ScheduleEvaluator();
    }

    /** Вершины с широкими окнами — классическая задача коммивояжёра. */
    private function openPoints(int $n, int $seed): array
    {
        mt_srand($seed);
        $points = [];

        for ($i = 0; $i < $n; $i++) {
            $points[] = new RoutePoint(55.70 + mt_rand(0, 1000) / 10000, 37.55 + mt_rand(0, 1500) / 10000, 0, 24 * 60, 0);
        }

        return $points;
    }

    private function matrix(array $points): array
    {
        return (new DistanceMatrixService())->fromHaversine(array_map(fn ($p) => [$p->lat, $p->lng], $points));
    }

    private function bruteForce(array $points, array $matrix, ?int $startIdx = null): float
    {
        $idx = array_values(array_filter(array_keys($points), fn ($i) => $i !== $startIdx));
        $best = INF;

        foreach ($this->permutations($idx) as $order) {
            $eval = $this->evaluator->evaluate($points, $matrix, $order, $startIdx);
            $best = min($best, $eval['distance']);
        }

        return $best;
    }

    private function permutations(array $items): \Generator
    {
        if (count($items) <= 1) {
            yield $items;

            return;
        }

        foreach ($items as $k => $item) {
            $rest = $items;
            unset($rest[$k]);

            foreach ($this->permutations(array_values($rest)) as $perm) {
                yield [$item, ...$perm];
            }
        }
    }

    public function test_held_karp_matches_brute_force_optimum(): void
    {
        foreach ([11, 22, 33] as $seed) {
            $points = $this->openPoints(7, $seed);
            $matrix = $this->matrix($points);

            $solution = (new HeldKarpSolver())->solve($points, $matrix, array_keys($points), null, null, false)[0];

            $this->assertCount(7, $solution['order']);
            $this->assertEqualsWithDelta($this->bruteForce($points, $matrix), $solution['distance'], 1e-6, "seed $seed");
        }
    }

    public function test_held_karp_with_fixed_start_matches_brute_force(): void
    {
        $points = $this->openPoints(6, 7);
        $points[] = RoutePoint::start(55.75, 37.62, 600);
        $startIdx = count($points) - 1;
        $matrix = $this->matrix($points);

        $solution = (new HeldKarpSolver())->solve($points, $matrix, range(0, 5), $startIdx, null, false)[0];

        $this->assertEqualsWithDelta($this->bruteForce($points, $matrix, $startIdx), $solution['distance'], 1e-6);
    }

    public function test_time_windows_override_geometry(): void
    {
        // A рядом со стартом, но начинается в 19:00; B дальше (≈72 мин пешком), но в 15:00.
        $points = [
            new RoutePoint(55.7500, 37.6200, 19 * 60, 19 * 60 + 10, 120),
            new RoutePoint(55.7800, 37.6600, 15 * 60, 15 * 60 + 10, 120),
            RoutePoint::start(55.7501, 37.6201, 13 * 60 + 30),
        ];
        $matrix = $this->matrix($points);

        $solution = (new HeldKarpSolver())->solve($points, $matrix, [0, 1], 2, null, false)[0];

        $this->assertSame([1, 0], $solution['order']);
    }

    public function test_conflicting_events_are_dropped_not_violated(): void
    {
        // Два двухчасовых события в одно время в разных концах города.
        $points = [
            new RoutePoint(55.75, 37.60, 19 * 60, 19 * 60 + 10, 120),
            new RoutePoint(55.80, 37.70, 19 * 60, 19 * 60 + 10, 120),
            new RoutePoint(55.76, 37.61, 10 * 60, 21 * 60, 60),
        ];
        $matrix = $this->matrix($points);

        $solution = (new HeldKarpSolver())->solve($points, $matrix, [0, 1, 2], null, null, false)[0];

        $this->assertCount(2, $solution['order']);
        $this->assertTrue($this->evaluator->evaluate($points, $matrix, $solution['order'], null)['feasible']);
    }

    public function test_end_time_limits_route(): void
    {
        $points = [
            new RoutePoint(55.75, 37.60, 12 * 60, 20 * 60, 120),
            new RoutePoint(55.751, 37.601, 12 * 60, 20 * 60, 120),
            new RoutePoint(55.752, 37.602, 12 * 60, 20 * 60, 120),
            RoutePoint::start(55.75, 37.60, 12 * 60),
        ];
        $matrix = $this->matrix($points);

        // До 16:30 помещаются только две двухчасовые точки.
        $solution = (new HeldKarpSolver())->solve($points, $matrix, [0, 1, 2], 3, 16 * 60 + 30, false)[0];

        $this->assertCount(2, $solution['order']);
        $this->assertLessThanOrEqual(16 * 60 + 30, $solution['finish']);
    }

    public function test_heuristic_is_feasible_and_close_to_exact(): void
    {
        $points = $this->openPoints(10, 99);
        $matrix = $this->matrix($points);
        $exact = (new HeldKarpSolver())->solve($points, $matrix, array_keys($points), null, null, false)[0];
        $heuristic = (new HeuristicSolver($this->evaluator))->solve($points, $matrix, array_keys($points), null, null, false)[0];

        $this->assertCount(10, $heuristic['order']);
        $this->assertGreaterThanOrEqual($exact['distance'] - 1e-6, $heuristic['distance']);
        // 2-opt + Or-opt на 10 точках обычно в пределах нескольких процентов от оптимума.
        $this->assertLessThanOrEqual($exact['distance'] * 1.15, $heuristic['distance']);
    }

    public function test_variants_are_distinct(): void
    {
        $points = $this->openPoints(5, 5);
        $variants = (new HeldKarpSolver())->solve($points, $this->matrix($points), array_keys($points), null, null, false, 3);

        $this->assertCount(3, $variants);
        $this->assertCount(3, array_unique(array_map(fn ($v) => implode('-', $v['order']), $variants)));
        $this->assertLessThanOrEqual($variants[1]['distance'], $variants[0]['distance']);
    }
}
