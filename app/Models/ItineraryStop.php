<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItineraryStop extends Model
{
    use HasFactory;

    protected $fillable = [
        'itinerary_id',
        'event_id',
        'position',
        'distance_from_prev_km',
    ];

    protected function casts(): array
    {
        return [
            'distance_from_prev_km' => 'decimal:3',
        ];
    }

    public function itinerary(): BelongsTo
    {
        return $this->belongsTo(Itinerary::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
