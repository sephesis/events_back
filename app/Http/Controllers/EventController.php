<?php

namespace App\Http\Controllers;

use App\Http\Requests\Event\IndexRequest;
use App\Http\Resources\EventResource;
use App\Models\City;
use App\Models\Event;
use App\Services\EventSourceService;
use Illuminate\Http\JsonResponse;

class EventController extends Controller
{
    public function __construct(private readonly EventSourceService $source)
    {
    }

    public function index(IndexRequest $request): JsonResponse
    {
        $cityCode = $request->validated('city');

        if (! isset(City::CITIES_MAP_KG[$cityCode])) {
            return response()->json(['message' => 'Неизвестный город.'], 422);
        }

        $result = $this->source->search([
            'city_code' => $cityCode,
            'categories' => $request->categories(),
            'budget' => $request->budget(),
            'date' => $request->validated('date'),
            'q' => $request->validated('q'),
        ]);

        return response()->json([
            'events' => EventResource::collection($result['events']),
            'source' => $result['source'],
        ]);
    }

    public function show(Event $event): JsonResponse
    {
        return response()->json(['event' => new EventResource($event)]);
    }
}
