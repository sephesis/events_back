<?php

namespace App\Services;

use App\Models\Event;
use App\Utils\Geo;
use Illuminate\Support\Collection;

/**
 * Builds a walking itinerary out of a fixed set of chosen events/places.
 *
 * Since we have no access to a real routing engine here, "distance" is
 * straight-line (haversine) between coordinates, and the "optimal" order
 * is the permutation with the lowest total distance (brute force for small
 * stop counts, nearest-neighbour greedy fallback for larger ones).
 */
class RouteBuilder
{
    private const MAX_BRUTE_FORCE_STOPS = 6;
    private const MAX_VARIANTS = 3;

    public function build(array $eventIds, ?float $budget = null): array
    {
        $events = Event::whereIn('id', $eventIds)->get()->keyBy('id');
        $ordered = collect($eventIds)->map(fn ($id) => $events->get($id))->filter()->values();

        $withCoords = $ordered->filter->hasCoordinates()->values();
        $withoutCoords = $ordered->reject->hasCoordinates()->values();

        if ($withCoords->count() < 2) {
            return [
                'variants' => [$this->variantFromOrder($withCoords->concat($withoutCoords))],
                'skipped_event_ids' => [],
                'over_budget' => $this->isOverBudget($ordered, $budget),
            ];
        }

        $orders = $withCoords->count() <= self::MAX_BRUTE_FORCE_STOPS
            ? $this->permutations($withCoords->all())
            : [$this->nearestNeighborOrder($withCoords->all())];

        $variants = collect($orders)
            ->map(fn (array $order) => $this->variantFromOrder(collect($order)))
            ->unique(fn (array $variant) => implode('-', $variant['order']))
            ->sortBy('total_distance_km')
            ->take(self::MAX_VARIANTS)
            ->values()
            ->map(function (array $variant) use ($withoutCoords) {
                foreach ($withoutCoords as $event) {
                    $variant['order'][] = $event->id;
                    $variant['stops'][] = $this->stopPayload($event, null);
                }

                return $variant;
            })
            ->all();

        return [
            'variants' => $variants,
            'skipped_event_ids' => [],
            'over_budget' => $this->isOverBudget($ordered, $budget),
        ];
    }

    private function variantFromOrder(Collection $events): array
    {
        $stops = [];
        $totalDistance = 0.0;
        $totalCost = 0.0;
        $prev = null;

        foreach ($events as $event) {
            $distance = null;

            if ($prev && $prev->hasCoordinates() && $event->hasCoordinates()) {
                $distance = round(Geo::distanceKm(
                    (float) $prev->lat,
                    (float) $prev->lng,
                    (float) $event->lat,
                    (float) $event->lng
                ), 3);
                $totalDistance += $distance;
            }

            $stops[] = $this->stopPayload($event, $distance);
            $totalCost += (float) ($event->price_min ?? 0);
            $prev = $event;
        }

        return [
            'order' => $events->pluck('id')->all(),
            'stops' => $stops,
            'total_distance_km' => round($totalDistance, 3),
            'total_cost' => round($totalCost, 2),
        ];
    }

    private function stopPayload(Event $event, ?float $distanceFromPrev): array
    {
        return [
            'event_id' => $event->id,
            'title' => $event->title,
            'place' => $event->place_name,
            'lat' => $event->lat !== null ? (float) $event->lat : null,
            'lng' => $event->lng !== null ? (float) $event->lng : null,
            'price_min' => $event->price_min !== null ? (float) $event->price_min : null,
            'distance_from_prev_km' => $distanceFromPrev,
        ];
    }

    private function isOverBudget(Collection $events, ?float $budget): bool
    {
        if ($budget === null) {
            return false;
        }

        return $events->sum(fn (Event $e) => (float) ($e->price_min ?? 0)) > $budget;
    }

    /**
     * @param Event[] $items
     * @return array<int, Event[]>
     */
    private function permutations(array $items): array
    {
        if (count($items) <= 1) {
            return [$items];
        }

        $permutations = [];

        foreach ($items as $key => $item) {
            $remaining = $items;
            unset($remaining[$key]);

            foreach ($this->permutations(array_values($remaining)) as $permutation) {
                array_unshift($permutation, $item);
                $permutations[] = $permutation;
            }
        }

        return $permutations;
    }

    /**
     * @param Event[] $items
     * @return Event[]
     */
    private function nearestNeighborOrder(array $items): array
    {
        $remaining = $items;
        $order = [array_shift($remaining)];

        while (! empty($remaining)) {
            $current = end($order);
            $nearestKey = null;
            $nearestDistance = null;

            foreach ($remaining as $key => $candidate) {
                $distance = Geo::distanceKm(
                    (float) $current->lat,
                    (float) $current->lng,
                    (float) $candidate->lat,
                    (float) $candidate->lng
                );

                if ($nearestDistance === null || $distance < $nearestDistance) {
                    $nearestDistance = $distance;
                    $nearestKey = $key;
                }
            }

            $order[] = $remaining[$nearestKey];
            unset($remaining[$nearestKey]);
        }

        return $order;
    }
}
