<?php

namespace App\Utils;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class ImageFormatter
{
    public static function prepare($images)
    {
        return collect($images)
        ->map(function ($image) {
            if (is_array($image) && isset($image['image'])) {
                return $image['image'];
            }
            return $image;
        })
        ->filter() 
        ->values() 
        ->toArray();
    }
}