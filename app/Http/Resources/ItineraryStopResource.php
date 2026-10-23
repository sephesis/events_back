<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItineraryStopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'position' => $this->position,
            'distance_from_prev_km' => $this->distance_from_prev_km !== null ? (float) $this->distance_from_prev_km : null,
            'event' => new EventResource($this->whenLoaded('event')),
        ];
    }
}
