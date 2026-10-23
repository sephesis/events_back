<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Itinerary extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'city_code',
        'budget',
        'total_distance_km',
        'total_cost',
        'title',
    ];

    protected function casts(): array
    {
        return [
            'budget' => 'decimal:2',
            'total_distance_km' => 'decimal:3',
            'total_cost' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stops(): HasMany
    {
        return $this->hasMany(ItineraryStop::class)->orderBy('position');
    }
}
