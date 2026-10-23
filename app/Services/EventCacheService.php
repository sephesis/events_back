<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Collection;

class EventCacheService
{
    /**
     * Persist raw KudaGo event payloads as Event models and return them.
     *
     * @param array $rawEvents Raw "results" array from the KudaGo events endpoint.
     * @return Collection<int, Event>
     */
    public function upsertFromKudaGo(array $rawEvents, string $cityCode): Collection
    {
        $fetchedAt = now();

        return collect($rawEvents)
            ->filter(fn ($raw) => isset($raw['id']))
            ->map(fn (array $raw) => $this->upsertOne($raw, $cityCode, $fetchedAt))
            ->values();
    }

    private function upsertOne(array $raw, string $cityCode, $fetchedAt): Event
    {
        $place = $raw['place'] ?? [];
        // Prefer the venue's own coords; fall back to the city's if the
        // event has no specific place (e.g. an online event).
        [$lat, $lng] = $this->normalizeCoords($place['coords'] ?? ($raw['location']['coords'] ?? []));
        [$priceMin, $priceMax] = $this->parsePrice($raw['price'] ?? null);

        return Event::updateOrCreate(
            [
                'source' => 'kudago',
                'external_id' => (string) $raw['id'],
            ],
            [
                'city_code' => $cityCode,
                'category_group' => $raw['categories'][0] ?? null,
                'title' => $raw['title'] ?? '',
                'short_title' => $raw['short_title'] ?? null,
                'description' => $raw['description'] ?? null,
                'tagline' => $raw['tagline'] ?? null,
                'place_name' => $place['title'] ?? null,
                'address' => $place['address'] ?? null,
                'lat' => $lat,
                'lng' => $lng,
                'price_min' => $priceMin,
                'price_max' => $priceMax,
                'is_free' => (bool) ($raw['is_free'] ?? false),
                'age_restriction' => $raw['age_restriction'] ?? null,
                'images' => $raw['images'] ?? [],
                'raw_payload' => $raw,
                'fetched_at' => $fetchedAt,
            ]
        );
    }

    /**
     * KudaGo's "coords" objects have been observed with lat/lon swapped
     * (the value under the "lat" key is actually the longitude, and vice
     * versa) — inconsistently, so we can't just always swap. Since this app
     * only serves Moscow/SPB, latitude (~55-60) is reliably the larger of
     * the two values and longitude (~30-40) the smaller, regardless of
     * which key it came from.
     *
     * @return array{0: ?float, 1: ?float} [lat, lng]
     */
    private function normalizeCoords(array $coords): array
    {
        $a = isset($coords['lat']) ? (float) $coords['lat'] : null;
        $b = isset($coords['lon']) ? (float) $coords['lon'] : null;

        if ($a === null || $b === null) {
            return [null, null];
        }

        return $a > $b ? [$a, $b] : [$b, $a];
    }

    /**
     * KudaGo returns price as free text (e.g. "от 300 до 500 руб."). Pull out
     * the numbers we can find; there is no guaranteed structured format.
     */
    private function parsePrice(?string $price): array
    {
        if (! $price) {
            return [null, null];
        }

        preg_match_all('/\d+/', $price, $matches);
        $numbers = array_map('intval', $matches[0] ?? []);

        if (empty($numbers)) {
            return [null, null];
        }

        return [min($numbers), max($numbers)];
    }
}
