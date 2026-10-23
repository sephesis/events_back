<?php

namespace App\Http\Controllers;

use App\Http\Requests\Itinerary\BuildRequest;
use App\Http\Requests\Itinerary\PlanRequest;
use App\Http\Requests\Itinerary\StoreRequest;
use App\Http\Resources\ItineraryResource;
use App\Models\Event;
use App\Models\Itinerary;
use App\Models\UserInteraction;
use App\Services\ItineraryPlanner;
use App\Services\Recommendation\MarkovChainService;
use App\Services\RouteBuilder;
use App\Services\Routing\DistanceMatrixService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ItineraryController extends Controller
{
    public function __construct(
        private readonly RouteBuilder $routeBuilder,
        private readonly ItineraryPlanner $planner,
        private readonly MarkovChainService $markov,
        private readonly DistanceMatrixService $matrices,
    ) {
    }

    /** Оптимальный порядок для выбранных пользователем мероприятий. */
    public function build(BuildRequest $request): JsonResponse
    {
        $result = $this->routeBuilder->build(
            $request->validated('event_ids'),
            $request->validated('budget'),
            $request->routeOptions()
        );

        return response()->json($result);
    }

    /** Автоматический персонализированный маршрут (рекомендации + оптимизация). */
    public function plan(PlanRequest $request): JsonResponse
    {
        $result = $this->planner->plan($request->user('sanctum'), [
            ...$request->routeOptions(),
            'city_code' => $request->validated('city_code'),
            'budget' => $request->validated('budget'),
            'stops' => $request->validated('stops'),
            'categories' => $request->validated('categories') ?? [],
            'exclude_ids' => $request->validated('exclude_ids') ?? [],
        ]);

        return response()->json($result);
    }

    /**
     * Сохраняет маршрут в том порядке, который выбрал пользователь
     * (обычно это один из вариантов, предложенных оптимизатором).
     */
    public function store(StoreRequest $request): JsonResponse
    {
        $eventIds = $request->validated('event_ids');
        $events = Event::whereIn('id', $eventIds)->get()->keyBy('id');
        $ordered = collect($eventIds)->map(fn ($id) => $events->get($id))->filter()->values();
        $legs = $this->legDistances($ordered);
        $user = $request->user();

        $itinerary = DB::transaction(function () use ($request, $user, $ordered, $legs) {
            $itinerary = Itinerary::create([
                'user_id' => $user->id,
                'city_code' => $request->validated('city_code'),
                'budget' => $request->validated('budget'),
                'total_distance_km' => round(array_sum(array_filter($legs)), 3),
                'total_cost' => $ordered->sum(fn (Event $e) => $e->is_free ? 0 : (float) ($e->price_min ?? 0)),
                'title' => $request->validated('title'),
            ]);

            foreach ($ordered as $position => $event) {
                $itinerary->stops()->create([
                    'event_id' => $event->id,
                    'position' => $position + 1,
                    'distance_from_prev_km' => $legs[$position],
                ]);
            }

            $user->interactions()->create([
                'itinerary_id' => $itinerary->id,
                'action' => UserInteraction::ACTION_SAVE_ROUTE,
                'meta' => ['event_ids' => $ordered->pluck('id')->all()],
            ]);

            return $itinerary;
        });

        // Новый маршрут меняет общую матрицу переходов — сбрасываем её кэш.
        $this->markov->forgetGlobal();

        return response()->json([
            'itinerary' => new ItineraryResource($itinerary->load('stops.event')),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $itineraries = $request->user()
            ->itineraries()
            ->with('stops.event')
            ->latest()
            ->get();

        return response()->json([
            'itineraries' => ItineraryResource::collection($itineraries),
        ]);
    }

    public function show(Request $request, Itinerary $itinerary): JsonResponse
    {
        abort_if($itinerary->user_id !== $request->user()->id, 403);

        return response()->json([
            'itinerary' => new ItineraryResource($itinerary->load('stops.event')),
        ]);
    }

    public function destroy(Request $request, Itinerary $itinerary): JsonResponse
    {
        abort_if($itinerary->user_id !== $request->user()->id, 403);

        $itinerary->delete();
        $this->markov->forgetGlobal();

        return response()->json(['message' => 'Маршрут удалён.']);
    }

    /**
     * «Похоже на то, что вам понравится»: новый маршрут, спланированный
     * по истории пользователя в городе и с бюджетом его последнего маршрута.
     */
    public function recommendations(Request $request): JsonResponse
    {
        $user = $request->user();
        $last = $user->itineraries()->latest()->first();

        if (! $last) {
            return response()->json(['suggestions' => []]);
        }

        $plan = $this->planner->plan($user, [
            'city_code' => $last->city_code,
            'budget' => $last->budget !== null ? (float) $last->budget : null,
            'stops' => 3,
            'date' => now()->toDateString(),
            'start_time' => now()->hour < 20 ? now()->addHour()->format('H:00') : '12:00',
            'end_time' => '23:30',
        ]);

        if (empty($plan['variants'])) {
            return response()->json(['suggestions' => []]);
        }

        return response()->json([
            'suggestions' => [[
                'based_on_itinerary_id' => $last->id,
                'city_code' => $last->city_code,
                'budget' => $last->budget !== null ? (float) $last->budget : null,
                'variants' => $plan['variants'],
                'explanations' => $plan['explanations'],
                'route_date' => $plan['route_date'],
            ]],
        ]);
    }

    /** @return array<int, ?float> расстояние от предыдущей точки для каждой остановки */
    private function legDistances($ordered): array
    {
        $withCoords = $ordered->filter->hasCoordinates()->values();
        $matrix = $withCoords->count() > 1
            ? $this->matrices->build($withCoords->map(fn (Event $e) => [(float) $e->lat, (float) $e->lng])->all())
            : null;

        $legs = [];
        $prevIdx = null;
        $coordIdx = 0;

        foreach ($ordered as $event) {
            if (! $event->hasCoordinates()) {
                $legs[] = null;
                continue;
            }

            $legs[] = $prevIdx !== null && $matrix ? $matrix['distance'][$prevIdx][$coordIdx] : null;
            $prevIdx = $coordIdx++;
        }

        return $legs;
    }
}
