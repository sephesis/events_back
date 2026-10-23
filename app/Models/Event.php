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
        'opens_at',
        'closes_at',
        'duration_min',
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

    /**
     * Описание категории из config/recommender.php: name, kind, duration.
     */
    public function categoryMeta(): array
    {
        return config('recommender.categories.' . $this->category_group)
            ?? config('recommender.default_category');
    }

    /** fixed — начало в конкретное время; open — в любое время работы. */
    public function isFixedTime(): bool
    {
        return $this->categoryMeta()['kind'] === 'fixed' && $this->starts_at !== null;
    }

    public function visitDurationMinutes(): int
    {
        if ($this->duration_min) {
            return (int) $this->duration_min;
        }

        if ($this->isFixedTime() && $this->ends_at && $this->starts_at->isSameDay($this->ends_at)) {
            $minutes = (int) $this->starts_at->diffInMinutes($this->ends_at);

            if ($minutes > 0) {
                return $minutes;
            }
        }

        return (int) $this->categoryMeta()['duration'];
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('ends_at')->where(function (Builder $q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '>=', now()->startOfDay());
            })->orWhere('ends_at', '>=', now());
        });
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
