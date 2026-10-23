<?php

namespace App\Http\Resources;

use App\Utils\ImageFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $category = $this->categoryMeta();

        return [
            'id' => $this->id,
            'title' => htmlspecialchars_decode(strip_tags($this->title)) ?? '',
            'short_title' => $this->short_title ?? '',
            'place' => $this->place_name ?? '',
            'address' => $this->address ?? '',
            'lat' => $this->lat !== null ? (float) $this->lat : null,
            'lng' => $this->lng !== null ? (float) $this->lng : null,
            'age_restriction' => $this->age_restriction ?? '',
            'images' => ! empty($this->images) ? ImageFormatter::prepare($this->images) : [],
            'tagline' => isset($this->tagline) ? strip_tags($this->tagline) : '',
            'description' => isset($this->description) ? strip_tags($this->description) : '',
            'price_min' => $this->price_min !== null ? (float) $this->price_min : null,
            'price_max' => $this->price_max !== null ? (float) $this->price_max : null,
            'is_free' => (bool) $this->is_free,
            'category_group' => $this->category_group ?? '',
            'category_name' => $category['name'],
            'kind' => $category['kind'],
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'opens_at' => $this->opens_at ? substr($this->opens_at, 0, 5) : null,
            'closes_at' => $this->closes_at ? substr($this->closes_at, 0, 5) : null,
            'duration_min' => $this->visitDurationMinutes(),
            'source' => $this->source,
            'link' => $this->source === 'kudago' && isset($this->raw_payload['site_url']) ? $this->raw_payload['site_url'] : null,
        ];
    }
}
