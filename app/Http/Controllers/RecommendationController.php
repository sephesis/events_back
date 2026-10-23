<?php

namespace App\Http\Controllers;

use App\Http\Requests\Recommendation\RecommendRequest;
use App\Http\Resources\RecommendationResource;
use App\Models\Event;
use App\Services\EventSourceService;
use App\Services\Recommendation\MarkovChainService;
use App\Services\RecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecommendationController extends Controller
{
    public function __construct(
        private readonly EventSourceService $source,
        private readonly RecommendationService $recommender,
        private readonly MarkovChainService $markov,
    ) {
    }

    /**
     * Персонализированный список мероприятий, ранжированный по Score(e,u).
     * Если передан черновик маршрута, его последняя точка — текущее
     * состояние цепи Маркова и опорная точка для S_D.
     */
    public function recommend(RecommendRequest $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $found = $this->source->search([
            'city_code' => $request->validated('city_code'),
            'categories' => $request->validated('categories') ?? [],
            'date' => $request->validated('date'),
            'limit' => 150,
        ]);

        $currentIds = $request->validated('current_event_ids') ?? [];
        $current = Event::whereIn('id', $currentIds)->get()->sortBy(fn (Event $e) => array_search($e->id, $currentIds))->values();
        $last = $current->last();

        $budget = $request->validated('budget');
        $remaining = $budget !== null
            ? max(0.0, (float) $budget - $current->sum(fn (Event $e) => $e->is_free ? 0 : (float) ($e->price_min ?? 0)))
            : null;

        $context = [
            'anchor' => $last?->hasCoordinates() ? ['lat' => (float) $last->lat, 'lng' => (float) $last->lng] : $request->validated('start'),
            'date' => $request->validated('date'),
            'time_from' => $request->validated('time_from'),
            'time_to' => $request->validated('time_to'),
            'budget' => $remaining,
            'exclude_ids' => $currentIds,
        ];

        if ($last) {
            $context['from_state'] = $last->category_group;
        }

        $ranked = $this->recommender->diversify(
            $this->recommender->rank($user, $found['events'], $context),
            $request->validated('limit') ?? 12
        );

        $fromState = $context['from_state'] ?? ($user ? $this->markov->lastState($user) : null);

        return response()->json([
            'recommendations' => RecommendationResource::collection($ranked),
            'context' => [
                'from_state' => $fromState,
                'from_state_name' => $fromState ? config('recommender.categories.' . $fromState . '.name') : null,
                'remaining_budget' => $remaining,
                'personalized' => $user !== null,
                'source' => $found['source'],
                'weights' => config('recommender.weights'),
            ],
        ]);
    }

    /**
     * Матрица переходов цепи Маркова для визуализации и объяснимости.
     */
    public function model(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $model = $this->markov->model($user);
        $round = fn (array $matrix) => array_map(fn (array $row) => array_map(fn ($p) => round($p, 4), $row), $matrix);

        return response()->json([
            'states' => collect($model['states'])->map(fn (string $slug) => [
                'slug' => $slug,
                'name' => config('recommender.categories.' . $slug . '.name'),
            ])->values(),
            'matrix' => $round($model['matrix']),
            'personal_matrix' => $round($model['personal_matrix']),
            'global_matrix' => $round($model['global_matrix']),
            'initial' => array_map(fn ($p) => round($p, 4), $model['initial']),
            'lambda' => $model['lambda'],
            'personal_transitions' => $model['personal_transitions'],
            'global_transitions' => $model['global_transitions'],
            'last_state' => $user ? $this->markov->lastState($user) : null,
            'params' => config('recommender.markov'),
        ]);
    }
}
