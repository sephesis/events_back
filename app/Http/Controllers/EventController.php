<?php

namespace App\Http\Controllers;

class EventController extends Controller
{
    public function index()
    {
        $events = [
            '0' => [
                'id' => '1223',
                'name' => 'Тестовый ивент',
            ],
        ];
        return response()->json(['events' => $events]);
    }
}
