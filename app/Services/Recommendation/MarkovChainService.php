<?php

namespace App\Services\Recommendation;

use App\Models\Itinerary;
use App\Models\User;
use App\Models\UserInteraction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Цепь Маркова первого порядка над категориями мероприятий.
 *
 * Пользовательский сценарий S_u = (e1, e2, …, et) отображается
 * в последовательность состояний (категорий) c1 → c2 → … → ct.
 * Вероятность перехода оценивается по взвешенным частотам:
 *
 *     P_u(j | i) = (n_ij + α) / (Σ_k n_ik + α·K)          — сглаживание Лапласа
 *
 * где n_ij — сумма весов переходов i → j (вес зависит от источника
 * и затухает с давностью действия), K — число состояний.
 *
 * Проблема «первого пользователя» решается смешиванием личной матрицы
 * с общей (по всем пользователям):
 *
 *     P(j | i) = λ_i · P_u(j | i) + (1 − λ_i) · P_global(j | i),
 *     λ_i = n_i / (n_i + β)
 *
 * — чем больше у пользователя собственных переходов из состояния i,
 * тем сильнее модель опирается на его личную историю.
 */
class MarkovChainService
{
    /** @var array<string, array> мемоизация в пределах запроса */
    private array $memo = [];

    /** @return string[] */
    public function states(): array
    {
        return array_keys(config('recommender.categories'));
    }

    /**
     * Распределение вероятностей следующего состояния P(· | from).
     * Без контекста (from = null) — начальное распределение π.
     *
     * @return array<string, float>
     */
    public function nextDistribution(?User $user, ?string $from): array
    {
        $model = $this->model($user);

        if ($from === null || ! isset($model['matrix'][$from])) {
            return $model['initial'];
        }

        return $model['matrix'][$from];
    }

    public function probability(?User $user, ?string $from, ?string $to): float
    {
        if ($to === null) {
            return 0.0;
        }

        return $this->nextDistribution($user, $from)[$to] ?? 0.0;
    }

    /**
     * Полная модель: смешанная матрица, личная и общая матрицы, λ_i,
     * начальное распределение и объёмы данных — для объяснимости.
     */
    public function model(?User $user): array
    {
        $key = $user?->id ?? 'guest';

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $states = $this->states();
        $alpha = (float) config('recommender.markov.smoothing_alpha');
        $beta = (float) config('recommender.markov.personal_beta');

        $global = $this->globalCounts();
        $globalMatrix = $this->normalize($global['transitions'], $states, $alpha);
        $globalInitial = $this->normalizeVector($global['starts'] ?? [], $states, $alpha);

        $personal = $user ? $this->countTransitions($this->sequencesForUser($user)) : ['transitions' => [], 'occurrences' => [], 'starts' => []];
        $personalMatrix = $this->normalize($personal['transitions'], $states, $alpha);
        $personalInitial = $this->normalizeVector($personal['starts'], $states, $alpha);

        $matrix = [];
        $lambda = [];

        foreach ($states as $i) {
            $n = array_sum($personal['transitions'][$i] ?? []);
            $lambda[$i] = round($n / ($n + $beta), 4);

            foreach ($states as $j) {
                $matrix[$i][$j] = $lambda[$i] * $personalMatrix[$i][$j] + (1 - $lambda[$i]) * $globalMatrix[$i][$j];
            }
        }

        $n0 = array_sum($personal['starts']);
        $lambda0 = $n0 / ($n0 + $beta);
        $initial = [];

        foreach ($states as $j) {
            $initial[$j] = $lambda0 * $personalInitial[$j] + (1 - $lambda0) * $globalInitial[$j];
        }

        return $this->memo[$key] = [
            'states' => $states,
            'matrix' => $matrix,
            'initial' => $initial,
            'lambda' => $lambda,
            'personal_matrix' => $personalMatrix,
            'global_matrix' => $globalMatrix,
            'personal_transitions' => round(array_sum(array_map('array_sum', $personal['transitions'] ?: [[]])), 2),
            'global_transitions' => round(array_sum(array_map('array_sum', $global['transitions'] ?: [[]])), 2),
        ];
    }

    /** Последнее состояние пользователя — контекст, если черновик маршрута пуст. */
    public function lastState(User $user): ?string
    {
        $sequences = $this->sequencesForUser($user);
        $latest = null;

        foreach ($sequences as $sequence) {
            $last = end($sequence);

            if ($last && ($latest === null || $last['at'] > $latest['at'])) {
                $latest = $last;
            }
        }

        return $latest['state'] ?? null;
    }

    /**
     * Последовательности состояний пользователя из двух источников:
     *  - сохранённые маршруты (порядок остановок),
     *  - журнал действий (добавление в маршрут, просмотр подробностей),
     *    разбитый на сессии по паузе > session_gap_minutes.
     *
     * @return array<int, array<int, array{state: string, weight: float, at: int}>>
     */
    public function sequencesForUser(User $user): array
    {
        $weights = config('recommender.markov.source_weights');
        $sequences = [];

        $itineraries = $user->itineraries()->with('stops.event')->get();

        foreach ($itineraries as $itinerary) {
            $sequences[] = $this->itinerarySequence($itinerary, $weights['itinerary']);
        }

        $interactions = $user->interactions()
            ->whereIn('action', [UserInteraction::ACTION_ADD, UserInteraction::ACTION_DETAILS])
            ->whereNotNull('category_group')
            ->orderBy('created_at')
            ->get(['action', 'category_group', 'created_at']);

        $gap = (int) config('recommender.markov.session_gap_minutes') * 60;
        $current = [];
        $lastAt = null;

        foreach ($interactions as $interaction) {
            $at = $interaction->created_at->getTimestamp();

            if ($lastAt !== null && $at - $lastAt > $gap && $current) {
                $sequences[] = $current;
                $current = [];
            }

            $current[] = [
                'state' => $interaction->category_group,
                'weight' => $weights[$interaction->action] * $this->recency($interaction->created_at),
                'at' => $at,
            ];
            $lastAt = $at;
        }

        if ($current) {
            $sequences[] = $current;
        }

        return array_values(array_filter($sequences));
    }

    /**
     * Взвешенные частоты переходов n_ij и встречаемости состояний.
     * Вес перехода i → j — вес элемента j (источник × давность).
     * starts — частоты первых состояний сценариев (для начального распределения π).
     *
     * @return array{transitions: array<string, array<string, float>>, occurrences: array<string, float>, starts: array<string, float>}
     */
    public function countTransitions(array $sequences): array
    {
        $known = array_flip($this->states());
        $transitions = [];
        $occurrences = [];
        $starts = [];

        foreach ($sequences as $sequence) {
            $prev = null;

            foreach ($sequence as $item) {
                $state = $item['state'];

                if (! isset($known[$state])) {
                    $prev = null;
                    continue;
                }

                $occurrences[$state] = ($occurrences[$state] ?? 0) + $item['weight'];

                if ($prev === null) {
                    $starts[$state] = ($starts[$state] ?? 0) + $item['weight'];
                } else {
                    $transitions[$prev][$state] = ($transitions[$prev][$state] ?? 0) + $item['weight'];
                }

                $prev = $state;
            }
        }

        return ['transitions' => $transitions, 'occurrences' => $occurrences, 'starts' => $starts];
    }

    /**
     * Стохастическая матрица со сглаживанием Лапласа: каждая строка суммируется в 1.
     *
     * @return array<string, array<string, float>>
     */
    public function normalize(array $counts, array $states, float $alpha): array
    {
        $matrix = [];

        foreach ($states as $i) {
            $matrix[$i] = $this->normalizeVector($counts[$i] ?? [], $states, $alpha);
        }

        return $matrix;
    }

    private function normalizeVector(array $counts, array $states, float $alpha): array
    {
        $k = count($states);
        $total = array_sum($counts) + $alpha * $k;
        $vector = [];

        foreach ($states as $j) {
            $vector[$j] = $total > 0 ? (($counts[$j] ?? 0) + $alpha) / $total : 1 / $k;
        }

        return $vector;
    }

    private function globalCounts(): array
    {
        return Cache::remember('markov:global', (int) config('recommender.markov.global_cache_seconds'), function () {
            $weights = config('recommender.markov.source_weights');
            $sequences = [];

            Itinerary::with('stops.event')->latest()->limit(5000)->get()
                ->each(function (Itinerary $itinerary) use (&$sequences, $weights) {
                    $sequences[] = $this->itinerarySequence($itinerary, $weights['itinerary']);
                });

            return $this->countTransitions($sequences);
        });
    }

    public function forgetGlobal(): void
    {
        Cache::forget('markov:global');
        $this->memo = [];
    }

    private function itinerarySequence(Itinerary $itinerary, float $weight): array
    {
        $recency = $this->recency($itinerary->created_at);

        return $itinerary->stops
            ->filter(fn ($stop) => $stop->event && $stop->event->category_group)
            ->map(fn ($stop) => [
                'state' => $stop->event->category_group,
                'weight' => $weight * $recency,
                'at' => $itinerary->created_at?->getTimestamp() ?? 0,
            ])
            ->values()
            ->all();
    }

    private function recency(?CarbonInterface $at): float
    {
        if (! $at) {
            return 1.0;
        }

        $days = max(0, $at->diffInDays(now()));

        return 0.5 ** ($days / (float) config('recommender.markov.recency_half_life_days'));
    }
}
