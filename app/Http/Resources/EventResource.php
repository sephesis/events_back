<?php

namespace App\Http\Resources;

use App\Utils\ImageFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;

class EventResource extends JsonResource
{
    public function toArray(Request $request)
    {
        return [
            'id' => $this->id,
            'title' => htmlspecialchars_decode(strip_tags($this->title)) ?? '',
            'short_title' => $this->short_title ?? '',
            'location' => $this->location,
            'age_restriction' => isset($this->age_restriction) ? $this->age_restriction : '',
            //'place' => $this->place,
            'images' => isset($this->images) ? ImageFormatter::prepare($this->images) : ['https://dummyimage.com/350'],
            //'dates' => $this->dates,
            'description' => isset($this->description) ? strip_tags($this->description) : '',
        ];
    }
}