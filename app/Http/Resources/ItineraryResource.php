<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItineraryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'city_code' => $this->city_code,
            'budget' => $this->budget !== null ? (float) $this->budget : null,
            'total_distance_km' => $this->total_distance_km !== null ? (float) $this->total_distance_km : null,
            'total_cost' => $this->total_cost !== null ? (float) $this->total_cost : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'stops' => ItineraryStopResource::collection($this->whenLoaded('stops')),
        ];
    }
}
