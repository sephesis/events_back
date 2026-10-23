<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'source',
        'external_id',
        'city_code',
        'category_group',
        'title',
        'short_title',
        'description',
        'tagline',
        'place_name',
        'address',
        'lat',
        'lng',
        'price_min',
        'price_max',
        'is_free',
        'age_restriction',
        'images',
        'starts_at',
        'ends_at',
        'raw_payload',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'price_min' => 'decimal:2',
            'price_max' => 'decimal:2',
            'is_free' => 'boolean',
            'images' => 'array',
            'raw_payload' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'fetched_at' => 'datetime',
        ];
    }

    public function itineraryStops(): HasMany
    {
        return $this->hasMany(ItineraryStop::class);
    }

    public function hasCoordinates(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    public function scopeForCity(Builder $query, string $cityCode): Builder
    {
        return $query->where('city_code', $cityCode);
    }

    public function scopeWithinBudget(Builder $query, float $budget): Builder
    {
        return $query->where(function (Builder $q) use ($budget) {
            $q->where('is_free', true)
                ->orWhereNull('price_min')
                ->orWhere('price_min', '<=', $budget);
        });
    }
}
