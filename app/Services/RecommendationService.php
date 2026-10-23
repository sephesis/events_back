<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use App\Models\UserInteraction;
use App\Services\Recommendation\MarkovChainService;
use App\Services\Routing\RouteOptimizer;
use App\Utils\Geo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Итоговая оценка мероприятия из ВКР:
 *
 *     Score(e,u) = w1·P_M(e|u) + w2·S_I(e,u) + w3·S_D(e) + w4·S_T(e) + w5·S_B(e)
 *
 *  P_M — вероятность категории мероприятия по цепи Маркова (нормирована
 *        на максимум среди кандидатов, чтобы лежать в [0, 1]);
 *  S_I — соответствие интересам: явные интересы профиля + контентное
 *        сходство с историей + item-based коллаборативная составляющая
 *        (R_user гибридной модели); штраф за недавние отказы;
 *  S_D — пространственная доступность: exp(−d / D0) от предыдущей точки;
 *  S_T — соответствие временному окну пользователя;
 *  S_B — соответствие оставшемуся бюджету.
 *
 * Каждая компонента возвращается отдельно вместе с текстовым объяснением —
 * вероятностная модель интерпретируема, это одно из обоснований выбора
 * цепей Маркова в ВКР.
 */
class RecommendationService
{
    public function __construct(
        private readonly MarkovChainService $markov,
        private readonly RouteOptimizer $optimizer,
    ) {
    }

    /**
     * @param Collection<int, Event> $candidates
     * @param array{from_state?: ?string, anchor?: ?array, date?: ?string, time_from?: ?string, time_to?: ?string, budget?: ?float, exclude_ids?: int[]} $context
     * @return Collection<int, array{event: Event, score: float, components: array, markov_probability: float, reasons: string[]}>
     */
    public function rank(?User $user, Collection $candidates, array $context = []): Collection
    {
        $exclude = array_flip($context['exclude_ids'] ?? []);
        $candidates = $candidates->reject(fn (Event $e) => isset($exclude[$e->id]))->values();

        if ($candidates->isEmpty()) {
            return collect();
        }

        $weights = config('recommender.weights');
        $fromState = array_key_exists('from_state', $context)
            ? $context['from_state']
            : ($user ? $this->markov->lastState($user) : null);
        $distribution = $this->markov->nextDistribution($user, $fromState);
        $maxP = $candidates->map(fn (Event $e) => $distribution[$e->category_group] ?? 0.0)->max() ?: 1.0;

        $profile = $this->interestProfile($user, $candidates);
        $date = isset($context['date']) ? CarbonImmutable::parse($context['date'])->startOfDay() : null;

        return $candidates
            ->map(function (Event $event) use ($user, $context, $weights, $distribution, $maxP, $fromState, $profile, $date) {
                $p = $distribution[$event->category_group] ?? 0.0;
                $pm = $p / $maxP;
                [$si, $siReason] = $this->interestScore($event, $profile);
                [$sd, $sdReason] = $this->distanceScore($event, $context['anchor'] ?? null);
                [$st, $stReason] = $this->timeScore($event, $date, $context['time_from'] ?? null, $context['time_to'] ?? null);
                [$sb, $sbReason] = $this->budgetScore($event, $context['budget'] ?? null);

                $score = $weights['markov'] * $pm
                    + $weights['interest'] * $si
                    + $weights['distance'] * $sd
                    + $weights['time'] * $st
                    + $weights['budget'] * $sb;

                return [
                    'event' => $event,
                    'score' => round($score, 4),
                    'markov_probability' => round($p, 4),
                    'components' => [
                        'markov' => round($pm, 4),
                        'interest' => round($si, 4),
                        'distance' => round($sd, 4),
                        'time' => round($st, 4),
                        'budget' => round($sb, 4),
                    ],
                    'reasons' => array_values(array_filter([
                        $this->markovReason($user, $fromState, $event, $p),
                        $siReason,
                        $sdReason,
                        $stReason,
                        $sbReason,
                    ])),
                ];
            })
            ->sortByDesc('score')
            ->values();
    }

    /**
     * Переранжирование для разнообразия выдачи: каждый следующий объект той же
     * категории получает множитель penalty^k (k — сколько уже выбрано).
     * Используется и в списке рекомендаций, и в пошаговом планировщике.
     */
    public function diversify(Collection $ranked, int $limit, float $penalty = 0.5, array $alreadyChosen = []): Collection
    {
        $counts = array_count_values(array_filter($alreadyChosen));
        $pool = $ranked->values()->all();
        $result = [];

        while (count($result) < $limit && $pool) {
            $bestKey = null;
            $bestScore = -INF;

            foreach ($pool as $key => $item) {
                $adjusted = $item['score'] * ($penalty ** ($counts[$item['event']->category_group] ?? 0));

                if ($adjusted > $bestScore) {
                    [$bestKey, $bestScore] = [$key, $adjusted];
                }
            }

            $item = $pool[$bestKey];
            unset($pool[$bestKey]);
            $counts[$item['event']->category_group] = ($counts[$item['event']->category_group] ?? 0) + 1;
            $result[] = $item;
        }

        return collect($result);
    }

    // ---------------------------------------------------------------- S_I

    /**
     * Профиль интересов пользователя, рассчитанный один раз на запрос.
     */
    private function interestProfile(?User $user, Collection $candidates): array
    {
        $empty = ['interests' => [], 'history' => [], 'cf' => [], 'negative' => [], 'has_history' => false];

        if (! $user) {
            return $empty;
        }

        $interests = array_flip($user->interests ?? []);
        $halfLife = (float) config('recommender.markov.recency_half_life_days');

        // Положительная история: остановки сохранённых маршрутов + добавления в маршрут.
        $positive = collect();

        foreach ($user->itineraries()->with('stops.event')->get() as $itinerary) {
            foreach ($itinerary->stops as $stop) {
                if ($stop->event) {
                    $positive->push(['event_id' => $stop->event_id, 'category' => $stop->event->category_group, 'at' => $itinerary->created_at]);
                }
            }
        }

        $user->interactions()
            ->where('action', UserInteraction::ACTION_ADD)
            ->where('created_at', '>=', now()->subDays(180))
            ->get(['event_id', 'category_group', 'created_at'])
            ->each(fn ($i) => $positive->push(['event_id' => $i->event_id, 'category' => $i->category_group, 'at' => $i->created_at]));

        // Контентное сходство: доля категории в истории с затуханием по давности.
        $history = [];

        foreach ($positive as $row) {
            if (! $row['category']) {
                continue;
            }

            $days = $row['at'] ? max(0, $row['at']->diffInDays(now())) : 0;
            $history[$row['category']] = ($history[$row['category']] ?? 0) + 0.5 ** ($days / $halfLife);
        }

        $maxHistory = $history ? max($history) : 0;
        $history = $maxHistory > 0 ? array_map(fn ($v) => $v / $maxHistory, $history) : [];

        $negative = $user->interactions()
            ->whereIn('action', UserInteraction::NEGATIVE_ACTIONS)
            ->where('created_at', '>=', now()->subDays(30))
            ->pluck('event_id')
            ->filter()
            ->flip()
            ->all();

        return [
            'interests' => $interests,
            'history' => $history,
            'cf' => $this->collaborativeScores($candidates->pluck('id')->all(), $positive->pluck('event_id')->filter()->unique()->values()->all()),
            'negative' => $negative,
            'has_history' => $positive->isNotEmpty(),
        ];
    }

    /**
     * Item-based коллаборативная фильтрация по совместной встречаемости
     * мероприятий в маршрутах всех пользователей (косинусная мера):
     *     sim(e, h) = |I(e) ∩ I(h)| / sqrt(|I(e)| · |I(h)|),
     *     R_user(e) = Σ_h∈history sim(e, h), нормировано на максимум.
     *
     * @return array<int, float>
     */
    private function collaborativeScores(array $candidateIds, array $historyIds): array
    {
        if (empty($candidateIds) || empty($historyIds)) {
            return [];
        }

        $pairs = DB::table('itinerary_stops as a')
            ->join('itinerary_stops as b', function ($join) {
                $join->on('a.itinerary_id', '=', 'b.itinerary_id')->whereColumn('a.event_id', '!=', 'b.event_id');
            })
            ->whereIn('a.event_id', $candidateIds)
            ->whereIn('b.event_id', $historyIds)
            ->groupBy('a.event_id', 'b.event_id')
            ->select('a.event_id as e', 'b.event_id as h', DB::raw('COUNT(DISTINCT a.itinerary_id) as c'))
            ->get();

        if ($pairs->isEmpty()) {
            return [];
        }

        $freq = DB::table('itinerary_stops')
            ->whereIn('event_id', $pairs->pluck('e')->merge($pairs->pluck('h'))->unique())
            ->groupBy('event_id')
            ->pluck(DB::raw('COUNT(DISTINCT itinerary_id)'), 'event_id');

        $scores = [];

        foreach ($pairs as $pair) {
            $norm = sqrt(max(1, $freq[$pair->e] ?? 1) * max(1, $freq[$pair->h] ?? 1));
            $scores[$pair->e] = ($scores[$pair->e] ?? 0) + $pair->c / $norm;
        }

        $max = max($scores);

        return array_map(fn ($v) => $v / $max, $scores);
    }

    private function interestScore(Event $event, array $profile): array
    {
        $mix = config('recommender.interest_mix');
        $parts = [];
        $reason = null;
        $categoryName = $event->categoryMeta()['name'];

        if (! empty($profile['interests'])) {
            $match = isset($profile['interests'][$event->category_group]) ? 1.0 : 0.0;
            $parts[] = [$mix['profile'], $match];

            if ($match) {
                $reason = 'Совпадает с вашими интересами: ' . $categoryName;
            }
        }

        if ($profile['has_history']) {
            $affinity = $profile['history'][$event->category_group] ?? 0.0;
            $parts[] = [$mix['history'], $affinity];
            $cf = $profile['cf'][$event->id] ?? 0.0;
            $parts[] = [$mix['collaborative'], $cf];

            if (! $reason && $affinity >= 0.5) {
                $reason = 'Вы часто выбираете: ' . $categoryName;
            }

            if (! $reason && $cf >= 0.5) {
                $reason = 'Часто добавляют в маршрут вместе с вашими прошлыми выборами';
            }
        }

        // Нет ни профиля, ни истории — нейтральная оценка.
        $score = empty($parts)
            ? 0.5
            : array_sum(array_map(fn ($p) => $p[0] * $p[1], $parts)) / array_sum(array_column($parts, 0));

        if (isset($profile['negative'][$event->id])) {
            $score *= (float) config('recommender.negative_feedback_factor');
            $reason = 'Вы недавно отказывались от этого места';
        }

        return [$score, $reason];
    }

    // ---------------------------------------------------------------- S_D

    private function distanceScore(Event $event, ?array $anchor): array
    {
        if (! $anchor || ! isset($anchor['lat'], $anchor['lng']) || ! $event->hasCoordinates()) {
            return [0.5, null];
        }

        $km = Geo::distanceKm((float) $anchor['lat'], (float) $anchor['lng'], (float) $event->lat, (float) $event->lng);
        $score = exp(-$km / (float) config('recommender.walk_distance_scale_km'));

        return [$score, sprintf('%s км от предыдущей точки', number_format($km, 1, ',', ''))];
    }

    // ---------------------------------------------------------------- S_T

    private function timeScore(Event $event, ?CarbonImmutable $date, ?string $from, ?string $to): array
    {
        if ($date) {
            $window = $this->optimizer->windowFor($event, $date);

            if (isset($window['reason'])) {
                return [0.0, $window['reason']];
            }

            $userFrom = $this->minutes($from) ?? 0;
            $userTo = $this->minutes($to) ?? 24 * 60;

            if ($event->isFixedTime()) {
                $ok = $window['earliest'] >= $userFrom && $window['earliest'] <= $userTo;

                return [$ok ? 1.0 : 0.0, $ok ? 'Начало в ' . $event->starts_at->format('H:i') . ' — в вашем временном окне' : 'Начало в ' . $event->starts_at->format('H:i') . ' — вне вашего окна'];
            }

            $service = $event->visitDurationMinutes();
            $overlap = min($window['latest'] + $service, $userTo) - max($window['earliest'], $userFrom);
            $score = max(0.0, min(1.0, $overlap / max(1, $service)));

            return [$score, $score >= 1.0 ? 'Открыто в выбранное время' : null];
        }

        // Дата не задана: ближайшие события предпочтительнее далёких.
        if ($event->isFixedTime()) {
            $days = now()->startOfDay()->diffInDays($event->starts_at, false);

            if ($days < 0) {
                return [0.0, 'Уже прошло'];
            }

            $score = $days <= 7 ? 1.0 : exp(-($days - 7) / 14);

            return [$score, $days < 1 ? 'Сегодня в ' . $event->starts_at->format('H:i') : null];
        }

        return [1.0, null];
    }

    // ---------------------------------------------------------------- S_B

    private function budgetScore(Event $event, ?float $budget): array
    {
        if ($event->is_free) {
            return [1.0, 'Бесплатно'];
        }

        $price = $event->price_min !== null ? (float) $event->price_min : null;

        if ($price === null) {
            return [0.6, null];
        }

        if ($budget === null) {
            return [1.0 - min($price, 5000) / 10000, null];
        }

        if ($price > $budget) {
            return [0.0, 'Дороже оставшегося бюджета'];
        }

        return [1.0 - 0.5 * $price / max($budget, 1), 'Укладывается в бюджет'];
    }

    // ---------------------------------------------------------------- explain

    private function markovReason(?User $user, ?string $fromState, Event $event, float $p): ?string
    {
        $to = $event->categoryMeta()['name'];
        $percent = (int) round($p * 100);

        if ($fromState === null) {
            return $p > 0 ? sprintf('Популярный выбор для начала: %s (%d%%)', $to, $percent) : null;
        }

        $from = config('recommender.categories.' . $fromState . '.name', $fromState);
        $who = $user && ($this->markov->model($user)['lambda'][$fromState] ?? 0) >= 0.5 ? 'вы обычно выбираете' : 'обычно выбирают';

        return sprintf('После «%s» %s «%s» — вероятность %d%%', $from, $who, $to, $percent);
    }

    private function minutes(?string $time): ?int
    {
        if (! $time) {
            return null;
        }

        [$h, $m] = array_map('intval', array_pad(explode(':', $time), 2, 0));

        return $h * 60 + $m;
    }
}
