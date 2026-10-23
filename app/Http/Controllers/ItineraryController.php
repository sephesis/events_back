<?php

namespace App\Http\Controllers;

use App\Http\Requests\Itinerary\BuildRequest;
use App\Http\Requests\Itinerary\StoreRequest;
use App\Http\Resources\ItineraryResource;
use App\Models\City;
use App\Models\Event;
use App\Models\Itinerary;
use App\Services\EventCacheService;
use App\Services\KudaGo;
use App\Services\RecommendationService;
use App\Services\RouteBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ItineraryController extends Controller
{
    public function __construct(
        private readonly RouteBuilder $routeBuilder,
        private readonly RecommendationService $recommendations,
        private readonly EventCacheService $eventCache,
    ) {
    }

    public function build(BuildRequest $request): JsonResponse
    {
        $result = $this->routeBuilder->build(
            $request->validated('event_ids'),
            $request->validated('budget')
        );

        return response()->json($result);
    }

    public function store(StoreRequest $request): JsonResponse
    {
        $eventIds = $request->validated('event_ids');
        $built = $this->routeBuilder->build($eventIds, $request->validated('budget'));
        $variant = $built['variants'][0] ?? null;

        if (! $variant) {
            throw ValidationException::withMessages([
                'event_ids' => ['Не удалось построить маршрут из выбранных точек.'],
            ]);
        }

        $itinerary = Itinerary::create([
            'user_id' => $request->user()->id,
            'city_code' => $request->validated('city_code'),
            'budget' => $request->validated('budget'),
            'total_distance_km' => $variant['total_distance_km'],
            'total_cost' => $variant['total_cost'],
            'title' => $request->validated('title'),
        ]);

        foreach ($variant['stops'] as $position => $stop) {
            $itinerary->stops()->create([
                'event_id' => $stop['event_id'],
                'position' => $position + 1,
                'distance_from_prev_km' => $stop['distance_from_prev_km'],
            ]);
        }

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

        return response()->json(['message' => 'Маршрут удалён.']);
    }

    public function recommendations(Request $request): JsonResponse
    {
        $user = $request->user();
        $patterns = $this->recommendations->suggestForUser($user);
        $suggestions = [];

        foreach ($patterns as $pattern) {
            $citySlug = City::CITIES_MAP_KG[$pattern['city_code']] ?? null;

            if (! $citySlug || empty($pattern['category_groups'])) {
                continue;
            }

            $candidateIds = [];

            foreach ($pattern['category_groups'] as $categoryGroup) {
                $raw = KudaGo::getEvents($citySlug, $categoryGroup, ['price', 'is_free']);
                $events = $this->eventCache->upsertFromKudaGo($raw['results'] ?? [], $pattern['city_code']);

                $best = $events
                    ->sortByDesc(fn (Event $event) => $this->recommendations->scoreEvent($user, $event))
                    ->first();

                if ($best) {
                    $candidateIds[] = $best->id;
                }
            }

            if (count($candidateIds) < 2) {
                continue;
            }

            $built = $this->routeBuilder->build($candidateIds, $pattern['budget']);

            $suggestions[] = [
                'based_on_itinerary_id' => $pattern['itinerary_id'],
                'city_code' => $pattern['city_code'],
                'budget' => $pattern['budget'],
                'variants' => $built['variants'],
            ];
        }

        return response()->json(['suggestions' => $suggestions]);
    }
}
