<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use App\Services\Recommendation\MarkovChainService;
use App\Services\Routing\RouteOptimizer;

/**
 * Автоматическое планирование персонализированного маршрута —
 * двухэтапный подход из ВКР:
 *
 *   История пользователя → Марковская модель → Рекомендованные объекты
 *       → Оптимизация → Персонализированный маршрут
 *
 * Этап 1 (рекомендация) выполняется пошагово: на каждом шаге кандидаты
 * ранжируются по Score(e,u) с контекстом «категория последней выбранной
 * точки» (состояние цепи Маркова) и «последняя точка» (для S_D).
 * Высокая вероятность ещё не означает, что объект стоит включать, —
 * поэтому кандидат принимается, только если маршрут с ним остаётся
 * допустимым по времени и бюджету (быстрая проверка оптимизатором).
 *
 * Этап 2 (маршрутизация) — итоговая оптимизация порядка посещения
 * отобранных точек по реальной пешеходной матрице.
 */
class ItineraryPlanner
{
    /** Сколько лучших кандидатов проверять на допустимость на каждом шаге. */
    private const SCREEN_TOP = 12;

    /** Множитель за повтор категории — чтобы маршрут не состоял из трёх концертов. */
    private const REPEAT_PENALTY = 0.5;

    public function __construct(
        private readonly EventSourceService $source,
        private readonly RecommendationService $recommender,
        private readonly RouteOptimizer $optimizer,
        private readonly MarkovChainService $markov,
    ) {
    }

    /**
     * @param array{city_code: string, date?: ?string, start_time?: ?string, end_time?: ?string, budget?: ?float, stops?: int, start?: ?array, categories?: string[], return_to_start?: bool, exclude_ids?: int[]} $params
     */
    public function plan(?User $user, array $params): array
    {
        $date = $params['date'] ?? now()->toDateString();
        $startTime = $params['start_time'] ?? '12:00';
        $endTime = $params['end_time'] ?? '22:00';
        $budget = isset($params['budget']) ? (float) $params['budget'] : null;
        $stops = max(1, min(8, (int) ($params['stops'] ?? 4)));
        $start = $params['start'] ?? null;

        $found = $this->source->search([
            'city_code' => $params['city_code'],
            'categories' => $params['categories'] ?? [],
            'budget' => $budget,
            'date' => $date,
            'limit' => 150,
        ]);

        $exclude = array_flip($params['exclude_ids'] ?? []);
        $candidates = $found['events']
            ->filter(fn (Event $e) => $e->hasCoordinates() && ! isset($exclude[$e->id]))
            ->values();

        $initialState = $user ? $this->markov->lastState($user) : null;
        $state = $initialState;
        $anchor = $start;
        $remaining = $budget;
        $selected = collect();
        $explanations = [];

        $routeOptions = [
            'start' => $start,
            'date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'return_to_start' => (bool) ($params['return_to_start'] ?? false),
        ];

        for ($step = 1; $step <= $stops; $step++) {
            $ranked = $this->recommender->rank($user, $candidates, [
                'from_state' => $state,
                'anchor' => $anchor,
                'date' => $date,
                'time_from' => $startTime,
                'time_to' => $endTime,
                'budget' => $remaining,
                'exclude_ids' => $selected->pluck('id')->all(),
            ]);

            $ranked = $this->recommender->diversify($ranked, self::SCREEN_TOP, self::REPEAT_PENALTY, $selected->pluck('category_group')->all());
            $accepted = null;

            foreach ($ranked->take(self::SCREEN_TOP) as $item) {
                /** @var Event $event */
                $event = $item['event'];
                $price = $event->is_free ? 0.0 : (float) ($event->price_min ?? 0);

                if ($item['components']['time'] <= 0.0 || ($remaining !== null && $price > $remaining)) {
                    continue;
                }

                $trial = $this->optimizer->optimize(
                    $selected->concat([$event]),
                    [...$routeOptions, 'matrix_provider' => 'haversine']
                );

                if (empty($trial['skipped']) && ! empty($trial['variants'])) {
                    $accepted = $item;
                    break;
                }
            }

            if (! $accepted) {
                break;
            }

            $event = $accepted['event'];
            $selected->push($event);
            $explanations[$event->id] = [
                'step' => $step,
                'from_state' => $state,
                'from_state_name' => $state ? config('recommender.categories.' . $state . '.name') : null,
                'score' => $accepted['score'],
                'markov_probability' => $accepted['markov_probability'],
                'components' => $accepted['components'],
                'reasons' => $accepted['reasons'],
            ];

            $state = $event->category_group;
            $anchor = ['lat' => (float) $event->lat, 'lng' => (float) $event->lng];

            if ($remaining !== null) {
                $remaining -= $event->is_free ? 0.0 : (float) ($event->price_min ?? 0);
            }
        }

        $route = $selected->isNotEmpty()
            ? $this->optimizer->optimize($selected, [...$routeOptions, 'budget' => $budget])
            : ['variants' => [], 'skipped' => [], 'skipped_event_ids' => [], 'over_budget' => false, 'budget' => $budget, 'route_date' => $date];

        return [
            ...$route,
            'explanations' => $explanations,
            'source' => $found['source'],
            'candidates_count' => $candidates->count(),
            'initial_state' => $initialState,
            'requested_stops' => $stops,
            'message' => $selected->count() < $stops
                ? sprintf('Удалось подобрать %d из %d точек с учётом времени и бюджета.', $selected->count(), $stops)
                : null,
        ];
    }
}
