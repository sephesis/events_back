<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    public function toArray(Request $request)
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'short_title' => $this->short_title,
            'location' => $this->location,
            'age_restriction' => (int) $this->age_restriction > 0 ? $this->age_restriction : '',
            'place' => $this->place,
            'images' => $this->images,
            'dates' => $this->dates,
            'description' => strip_tags($this->description),
        ];
    }
}