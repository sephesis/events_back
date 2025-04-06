<?php

namespace App\Http\Controllers;

use App\Http\Resources\EventResource;
use App\Models\Category;
use App\Services\Timepad;
use App\Services\KudaGo;
use App\Utils\ArrayMapper;
use Illuminate\Http\Request;
use App\Http\Requests\Event\StoreRequest;

class EventController extends Controller
{
    public function index(StoreRequest $request)
    {    
        if ($request->get('city')) {
            $city = \App\Models\City::CITIES_MAP_KG[$request->get('city')];
        }

        $events = KudaGo::getEvents($city, $request->get('areas'), ['age_restriction', 'images', 'short_title', 'dates', 'price', 'is_free']);

        $mappingFilePath = resource_path('mapping/events_key_mapping.json');
        $arrayMapper = new ArrayMapper($mappingFilePath);

        $standardizedArray = $arrayMapper->standardizeMultiDimensionalArray($events['results']);

        $eventObjects = array_map(function ($event) {
            return (object) $event;
        }, $standardizedArray);

        return response()->json(['events' => EventResource::collection($eventObjects)]);
    }
}
