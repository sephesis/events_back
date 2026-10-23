<?php

namespace App\Services\Routing;

use App\Models\Event;
use App\Utils\ImageFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Пространственно-временная оптимизация маршрута (второй этап двухэтапного
 * подхода ВКР: «Рекомендованные объекты → Оптимизация → Маршрут»).
 *
 *  1. Координаты стартовой точки и мероприятий;
 *  2. Матрица затрат (OSRM / haversine);
 *  3. Временные окна мероприятий на дату маршрута;
 *  4. Порядок посещения: Хелд–Карп (точно) при n ≤ exact_max_points,
 *     иначе ближайший сосед + 2-opt + Or-opt;
 *  5. Проверка бюджета и объяснение, какие точки не вошли и почему.
 */
class RouteOptimizer
{
    public function __construct(
        private readonly DistanceMatrixService $matrices,
        private readonly HeldKarpSolver $exact,
        private readonly HeuristicSolver $heuristic,
        private readonly ScheduleEvaluator $evaluator,
    ) {
    }

    /**
     * @param Collection<int, Event> $events
     * @param array{start?: ?array, date?: ?string, start_time?: ?string, end_time?: ?string, return_to_start?: bool, budget?: ?float, matrix_provider?: ?string} $options
     */
    public function optimize(Collection $events, array $options = []): array
    {
        $date = $this->resolveDate($events, $options['date'] ?? null);
        $startTime = $this->parseTime($options['start_time'] ?? null);
        $endTime = $this->parseTime($options['end_time'] ?? null);
        $returnToStart = (bool) ($options['return_to_start'] ?? false);
        $budget = isset($options['budget']) ? (float) $options['budget'] : null;
        $start = $options['start'] ?? null;

        $points = [];
        $skipped = [];
        $withoutCoords = [];

        foreach ($events as $event) {
            if (! $event->hasCoordinates()) {
                $withoutCoords[] = $event;
                continue;
            }

            $window = $this->windowFor($event, $date);

            if (isset($window['reason'])) {
                $skipped[] = $this->skip($event, $window['reason']);
                continue;
            }

            $earliest = $startTime !== null ? max($window['earliest'], $startTime) : $window['earliest'];

            if ($earliest > $window['latest']) {
                $skipped[] = $this->skip($event, 'Начинается раньше выбранного времени старта');
                continue;
            }

            $points[] = new RoutePoint(
                (float) $event->lat,
                (float) $event->lng,
                $earliest,
                $window['latest'],
                $event->visitDurationMinutes(),
                $event->is_free ? 0.0 : (float) ($event->price_min ?? 0),
                $event,
            );
        }

        $eventIdx = array_keys($points);
        $startIdx = null;

        if ($start && isset($start['lat'], $start['lng'])) {
            $startIdx = count($points);
            $firstWindow = empty($points) ? 12 * 60 : min(array_map(fn (RoutePoint $p) => $p->earliest, $points));
            $points[] = RoutePoint::start((float) $start['lat'], (float) $start['lng'], $startTime ?? max(0, $firstWindow - 30));
        }

        $coords = array_map(fn (RoutePoint $p) => [$p->lat, $p->lng], $points);
        // haversine — для быстрых проверок допустимости без сетевых запросов.
        $matrix = ($options['matrix_provider'] ?? null) === 'haversine'
            ? $this->matrices->fromHaversine($coords)
            : $this->matrices->build($coords);
        $maxVariants = (int) config('recommender.routing.max_variants', 3);
        $useExact = count($eventIdx) <= (int) config('recommender.routing.exact_max_points', 12);
        $solver = $useExact ? $this->exact : $this->heuristic;
        $solutions = $solver->solve($points, $matrix, $eventIdx, $startIdx, $endTime, $returnToStart, $maxVariants);

        $variants = array_map(
            fn (array $s) => $this->formatVariant($points, $matrix, $s['order'], $startIdx, $endTime, $returnToStart, $withoutCoords),
            $solutions
        );

        $routed = collect($solutions[0]['order'] ?? [])->flip();

        foreach ($eventIdx as $idx) {
            if (! $routed->has($idx)) {
                $skipped[] = $this->skip($points[$idx]->event, 'Не помещается по времени вместе с остальными точками');
            }
        }

        if (empty($variants) && ! empty($withoutCoords)) {
            $variants[] = $this->formatVariant($points, $matrix, [], $startIdx, $endTime, $returnToStart, $withoutCoords);
        }

        $totalCost = $variants[0]['total_cost'] ?? 0.0;

        return [
            'variants' => $variants,
            'skipped' => $skipped,
            'skipped_event_ids' => array_column($skipped, 'event_id'),
            'over_budget' => $budget !== null && $totalCost > $budget,
            'budget' => $budget,
            'route_date' => $date->toDateString(),
            'algorithm' => $useExact ? 'held-karp' : 'nearest-neighbor+2opt',
            'matrix_source' => $matrix['source'],
            'start' => $startIdx !== null ? ['lat' => $points[$startIdx]->lat, 'lng' => $points[$startIdx]->lng] : null,
        ];
    }

    /**
     * Окно допустимого начала посещения на дату маршрута либо причина,
     * по которой мероприятие в этот день недоступно.
     *
     * @return array{earliest?: int, latest?: int, reason?: string}
     */
    public function windowFor(Event $event, CarbonImmutable $date): array
    {
        $service = $event->visitDurationMinutes();

        if ($event->isFixedTime()) {
            $startsAt = CarbonImmutable::instance($event->starts_at);

            if (! $startsAt->isSameDay($date)) {
                return ['reason' => 'Проходит ' . $startsAt->format('d.m') . ', а маршрут построен на ' . $date->format('d.m')];
            }

            $minutes = $startsAt->hour * 60 + $startsAt->minute;

            return [
                'earliest' => $minutes,
                'latest' => $minutes + (int) config('recommender.routing.fixed_start_slack_min', 10),
            ];
        }

        if ($event->starts_at && CarbonImmutable::instance($event->starts_at)->startOfDay()->gt($date)) {
            return ['reason' => 'Открывается только ' . $event->starts_at->format('d.m')];
        }

        if ($event->ends_at && CarbonImmutable::instance($event->ends_at)->lt($date->startOfDay())) {
            return ['reason' => 'Уже завершилось'];
        }

        [$defaultOpen, $defaultClose] = config('recommender.default_opening_hours');
        $open = $this->parseTime($event->opens_at ?? $defaultOpen);
        $close = $this->parseTime($event->closes_at ?? $defaultClose);

        if ($close <= $open) {
            $close += 24 * 60; // работает после полуночи
        }

        return ['earliest' => $open, 'latest' => max($open, $close - $service)];
    }

    /**
     * Если дата не задана — день, на который приходится больше всего
     * мероприятий с фиксированным началом, иначе сегодня.
     */
    public function resolveDate(Collection $events, ?string $date): CarbonImmutable
    {
        if ($date) {
            return CarbonImmutable::parse($date)->startOfDay();
        }

        $today = CarbonImmutable::today();
        $days = $events
            ->filter(fn (Event $e) => $e->isFixedTime() && $e->starts_at->gte($today))
            ->map(fn (Event $e) => $e->starts_at->toDateString())
            ->countBy()
            ->sortKeys()
            ->sortDesc();

        return $days->isNotEmpty() ? CarbonImmutable::parse($days->keys()->first()) : $today;
    }

    private function formatVariant(array $points, array $matrix, array $order, ?int $startIdx, ?int $endTime, bool $returnToStart, array $withoutCoords): array
    {
        $eval = $this->evaluator->evaluate($points, $matrix, $order, $startIdx, $endTime, $returnToStart);
        $stops = [];
        $walking = 0.0;

        foreach ($eval['schedule'] as $row) {
            $walking += (float) ($row['travel_min'] ?? 0);
            $stops[] = $this->stopPayload($points[$row['index']]->event, $row);
        }

        foreach ($withoutCoords as $event) {
            $stops[] = $this->stopPayload($event, null);
        }

        $totalCost = collect($stops)->sum(fn (array $s) => $s['is_free'] ? 0 : (float) ($s['price_min'] ?? 0));

        return [
            'order' => array_column($stops, 'event_id'),
            'stops' => $stops,
            'total_distance_km' => round($eval['distance'], 2),
            'total_cost' => round($totalCost, 2),
            'total_duration_min' => (int) round($eval['duration']),
            'walking_min' => (int) round($walking),
            'starts_at' => isset($eval['schedule'][0]) ? $this->formatTime($eval['schedule'][0]['start']) : null,
            'ends_at' => ! empty($eval['schedule']) ? $this->formatTime($eval['finish']) : null,
        ];
    }

    private function stopPayload(Event $event, ?array $row): array
    {
        return [
            'event_id' => $event->id,
            'title' => html_entity_decode(strip_tags($event->title)),
            'place' => $event->place_name,
            'address' => $event->address,
            'lat' => $event->lat !== null ? (float) $event->lat : null,
            'lng' => $event->lng !== null ? (float) $event->lng : null,
            'price_min' => $event->price_min !== null ? (float) $event->price_min : null,
            'is_free' => (bool) $event->is_free,
            'category_group' => $event->category_group,
            'category_name' => $event->categoryMeta()['name'],
            'image' => ImageFormatter::prepare($event->images ?? [])[0] ?? null,
            'distance_from_prev_km' => $row['distance_from_prev_km'] ?? null,
            'travel_min' => $row['travel_min'] ?? null,
            'arrival' => $row ? $this->formatTime($row['arrival']) : null,
            'start' => $row ? $this->formatTime($row['start']) : null,
            'end' => $row ? $this->formatTime($row['finish']) : null,
            'wait_min' => $row ? (int) round($row['wait_min']) : null,
        ];
    }

    private function skip(Event $event, string $reason): array
    {
        return [
            'event_id' => $event->id,
            'title' => html_entity_decode(strip_tags($event->title)),
            'reason' => $reason,
        ];
    }

    private function parseTime(?string $time): ?int
    {
        if ($time === null || $time === '') {
            return null;
        }

        [$h, $m] = array_map('intval', array_pad(explode(':', $time), 2, 0));

        return $h * 60 + $m;
    }

    private function formatTime(float $minutes): string
    {
        $minutes = (int) round($minutes);
        $day = intdiv($minutes, 24 * 60);
        $time = sprintf('%02d:%02d', intdiv($minutes % (24 * 60), 60), $minutes % 60);

        return $day > 0 ? $time . ' (+' . $day . ')' : $time;
    }
}
