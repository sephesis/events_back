<?php

namespace App\Http\Resources;

use App\Utils\ImageFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => htmlspecialchars_decode(strip_tags($this->title)) ?? '',
            'short_title' => $this->short_title ?? '',
            'place' => $this->place_name ?? '',
            'address' => $this->address ?? '',
            'lat' => $this->lat !== null ? (float) $this->lat : null,
            'lng' => $this->lng !== null ? (float) $this->lng : null,
            'age_restriction' => $this->age_restriction ?? '',
            'images' => ! empty($this->images) ? ImageFormatter::prepare($this->images) : [asset('storage/public/images/dummies/event_dumm.jpg')],
            'tagline' => isset($this->tagline) ? strip_tags($this->tagline) : '',
            'description' => isset($this->description) ? strip_tags($this->description) : '',
            'price_min' => $this->price_min !== null ? (float) $this->price_min : null,
            'price_max' => $this->price_max !== null ? (float) $this->price_max : null,
            'is_free' => (bool) $this->is_free,
            'category_group' => $this->category_group ?? '',
            'link' => '#',
        ];
    }
}
