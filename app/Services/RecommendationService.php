<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Itinerary;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A lightweight, explainable content-based scoring model over a user's
 * itinerary history — no external ML infrastructure. It blends:
 *  - category affinity (how often the user picked this category before),
 *  - price affinity (closeness to the user's historical average spend),
 *  - a novelty bonus for categories the user hasn't tried (anti filter-bubble),
 * with recency decay so older choices matter less.
 */
class RecommendationService
{
    private const CATEGORY_WEIGHT = 0.55;
    private const PRICE_WEIGHT = 0.30;
    private const NOVELTY_BONUS = 0.15;
    private const RECENCY_HALF_LIFE_DAYS = 30.0;

    public function scoreEvent(User $user, Event $event): float
    {
        $history = $this->historyStops($user);

        if ($history->isEmpty()) {
            return 0.0;
        }

        $categoryScore = $this->categoryAffinity($history, $event->category_group);
        $priceScore = $this->priceAffinity($history, $event->price_min);
        $novelty = $categoryScore === 0.0 ? self::NOVELTY_BONUS : 0.0;

        return round(
            self::CATEGORY_WEIGHT * $categoryScore + self::PRICE_WEIGHT * $priceScore + $novelty,
            4
        );
    }

    /**
     * Summarise the user's recent saved itineraries as reusable "patterns"
     * (city + categories they combined) that a caller can refresh with
     * live/cached events and re-run through the RouteBuilder.
     *
     * @return array<int, array{itinerary_id:int, city_code:string, category_groups:array, budget:?float}>
     */
    public function suggestForUser(User $user, int $limit = 3): array
    {
        return $user->itineraries()
            ->with('stops.event')
            ->latest()
            ->take($limit)
            ->get()
            ->map(fn (Itinerary $itinerary) => [
                'itinerary_id' => $itinerary->id,
                'city_code' => $itinerary->city_code,
                'category_groups' => $itinerary->stops
                    ->pluck('event.category_group')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
                'budget' => $itinerary->budget !== null ? (float) $itinerary->budget : null,
            ])
            ->all();
    }

    private function historyStops(User $user): Collection
    {
        return $user->itineraries()
            ->with('stops.event')
            ->get()
            ->flatMap(fn (Itinerary $itinerary) => $itinerary->stops->map(fn ($stop) => [
                'event' => $stop->event,
                'days_ago' => $itinerary->created_at?->diffInDays(now()) ?? 0,
            ]))
            ->filter(fn (array $row) => $row['event'] !== null)
            ->values();
    }

    private function categoryAffinity(Collection $history, ?string $categoryGroup): float
    {
        if (! $categoryGroup) {
            return 0.0;
        }

        $totalWeight = $history->sum(fn (array $row) => $this->recencyWeight($row['days_ago']));

        if ($totalWeight <= 0.0) {
            return 0.0;
        }

        $matchedWeight = $history->sum(fn (array $row) => $row['event']->category_group === $categoryGroup
            ? $this->recencyWeight($row['days_ago'])
            : 0.0);

        return min(1.0, $matchedWeight / $totalWeight);
    }

    private function priceAffinity(Collection $history, mixed $price): float
    {
        if ($price === null) {
            return 0.5;
        }

        $prices = $history->pluck('event.price_min')
            ->filter(fn ($p) => $p !== null)
            ->map(fn ($p) => (float) $p);

        if ($prices->isEmpty()) {
            return 0.5;
        }

        $avg = $prices->avg();
        $spread = max($avg, 1.0);
        $delta = abs((float) $price - $avg) / $spread;

        return exp(-$delta);
    }

    private function recencyWeight(int $daysAgo): float
    {
        return 0.5 ** ($daysAgo / self::RECENCY_HALF_LIFE_DAYS);
    }
}
