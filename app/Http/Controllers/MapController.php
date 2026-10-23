<?php

namespace App\Http\Controllers;

use App\Http\Requests\Map\RouteLineRequest;
use App\Services\RoutingService;
use Illuminate\Http\JsonResponse;

class MapController extends Controller
{
    public function __construct(private readonly RoutingService $routing)
    {
    }

    public function routeLine(RouteLineRequest $request): JsonResponse
    {
        $route = $this->routing->getWalkingRoute($request->validated('points'));

        return response()->json([
            'polyline' => $route['polyline'] ?? null,
            'distance_km' => $route['distance_km'] ?? null,
            'duration_min' => $route['duration_min'] ?? null,
        ]);
    }
}
