<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        return [
            'source' => 'demo',
            'external_id' => (string) fake()->unique()->numberBetween(1, 10_000_000),
            'city_code' => '7700000000000',
            'category_group' => 'park',
            'title' => fake()->sentence(3),
            'place_name' => fake()->company(),
            'address' => fake()->address(),
            'lat' => 55.75 + fake()->randomFloat(4, -0.03, 0.03),
            'lng' => 37.62 + fake()->randomFloat(4, -0.05, 0.05),
            'price_min' => null,
            'is_free' => true,
            'opens_at' => '00:00',
            'closes_at' => '23:59',
        ];
    }

    public function category(string $slug): static
    {
        return $this->state(fn () => ['category_group' => $slug]);
    }

    public function at(float $lat, float $lng): static
    {
        return $this->state(fn () => ['lat' => $lat, 'lng' => $lng]);
    }

    /** Мероприятие с фиксированным началом (kind = fixed). */
    public function fixedAt(string $datetime, string $category = 'concert'): static
    {
        return $this->state(fn () => [
            'category_group' => $category,
            'starts_at' => $datetime,
            'ends_at' => now()->parse($datetime)->addHours(2),
            'opens_at' => null,
            'closes_at' => null,
        ]);
    }

    public function priced(float $price): static
    {
        return $this->state(fn () => ['price_min' => $price, 'is_free' => false]);
    }
}
