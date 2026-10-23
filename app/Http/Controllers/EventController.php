<?php

namespace App\Http\Controllers;

use App\Http\Requests\Event\StoreRequest;
use App\Http\Resources\EventResource;
use App\Models\City;
use App\Services\EventCacheService;
use App\Services\KudaGo;

class EventController extends Controller
{
    public function __construct(private readonly EventCacheService $eventCache)
    {
    }

    public function index(StoreRequest $request)
    {
        $cityCode = $request->get('city');

        if (! isset(City::CITIES_MAP_KG[$cityCode])) {
            return response()->json(['message' => 'Неизвестный город.'], 422);
        }

        $citySlug = City::CITIES_MAP_KG[$cityCode];

        $events = KudaGo::getEvents(
            $citySlug,
            (string) $request->get('areas', ''),
            ['age_restriction', 'images', 'short_title', 'dates', 'price', 'is_free']
        );

        $cachedEvents = $this->eventCache->upsertFromKudaGo($events['results'] ?? [], $cityCode);

        return response()->json(['events' => EventResource::collection($cachedEvents)]);
    }
}
