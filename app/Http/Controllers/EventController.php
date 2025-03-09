<?php

namespace App\Http\Controllers;

use App\Http\Resources\EventResource;
use App\Services\Timepad;
use App\Services\KudaGo;
use App\Utils\ArrayMapper;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function index(Request $request)
    {
        $city = '';
        
        if ($request->get('city')) {
            $city = \App\Models\City::CITIES_MAP_KG[$request->get('city')];
        }
        $kG = KudaGo::getEvents($city, ['age_restriction', 'images', 'short_title', 'dates']);

        if ($request->get('city')) {
            $city = \App\Models\City::CITIES_MAP_TIMEPAD[$request->get('city')];
        }
        $timepad = Timepad::getEvents($city);

        $mappingFilePath = resource_path('mapping/events_key_mapping.json');
        $arrayMapper = new ArrayMapper($mappingFilePath);

        $events = array_merge($timepad['values'], $kG['results']);

        $standardizedArray = $arrayMapper->standardizeMultiDimensionalArray($events);

        $eventObjects = array_map(function ($event) {
            return (object) $event;
        }, $standardizedArray);


      
        return response()->json(['events' => EventResource::collection($eventObjects)]);
    }
}
