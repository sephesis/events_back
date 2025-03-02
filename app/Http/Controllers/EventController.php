<?php

namespace App\Http\Controllers;

use App\Http\Resources\EventResource;
use App\Services\Timepad;
use App\Services\KudaGo;
use App\Utils\ArrayMapper;

class EventController extends Controller
{
    public function index()
    {
        $kG = KudaGo::getEvents('msk', ['age_restriction', 'images', 'short_title', 'dates']);

        $timepad = Timepad::getEvents();

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
