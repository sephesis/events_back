<?php

namespace App\Http\Controllers;

use App\Http\Resources\EventResource;
use App\Services\KudaGo;

class EventController extends Controller
{
    public function index()
    {
        $events = KudaGo::getEvents('msk', ['age_restriction', 'images', 'short_title', 'dates']);

        $eventObjects = array_map(function ($event) {
            return (object) $event;
        }, $events['results']);

        return response()->json(['events' => EventResource::collection($eventObjects)]);
    }
}
