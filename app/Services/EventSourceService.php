<?php

namespace App\Services;

use App\Models\City;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Единая точка получения мероприятий: «Запрос → Получение данных →
 * Проверка → Нормализация → Сохранение → Обновление» из ВКР.
 *
 * Основной источник — KudaGo (нормализуется и кэшируется в таблицу events).
 * Если API недоступен (сеть, блокировка, 4xx/5xx), используется локальный
 * демо-набор (source = demo), чтобы приложение и алгоритмы оставались
 * работоспособными. Места (парки, кафе, бары) KudaGo в ленте событий не
 * отдаёт, поэтому они всегда берутся из локальной базы.
 */
class EventSourceService
{
    public const SOURCE_KUDAGO = 'kudago';
    public const SOURCE_DEMO = 'demo';

    /** Категории-«места», которых нет в событиях KudaGo. */
    public const PLACE_CATEGORIES = ['park', 'cafe', 'bar'];

    private const KUDAGO_DOWN_KEY = 'kudago:down';

    public function __construct(private readonly EventCacheService $cache)
    {
    }

    /**
     * @param array{city_code: string, categories?: string[], budget?: ?float, date?: ?string, q?: ?string, limit?: int} $filters
     * @return array{events: Collection<int, Event>, source: string}
     */
    public function search(array $filters): array
    {
        $city = $filters['city_code'];
        $categories = array_values(array_filter($filters['categories'] ?? []));
        $source = $this->refreshFromKudaGo($city, $categories) ? self::SOURCE_KUDAGO : self::SOURCE_DEMO;

        $query = Event::query()
            ->forCity($city)
            ->upcoming()
            ->where(function (Builder $q) use ($source) {
                $q->where('source', $source)
                    ->orWhere(fn (Builder $q) => $q->where('source', self::SOURCE_DEMO)->whereIn('category_group', self::PLACE_CATEGORIES));
            });

        if ($categories) {
            $query->whereIn('category_group', $categories);
        }

        if (! empty($filters['budget'])) {
            $query->withinBudget((float) $filters['budget']);
        }

        if (! empty($filters['date'])) {
            $this->availableOn($query, CarbonImmutable::parse($filters['date']));
        }

        if (! empty($filters['q'])) {
            $term = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($filters['q'])) . '%';
            $query->where(fn (Builder $q) => $q->where('title', 'like', $term)
                ->orWhere('place_name', 'like', $term)
                ->orWhere('tagline', 'like', $term)
                ->orWhere('description', 'like', $term));
        }

        $events = $query
            ->orderByRaw('starts_at IS NULL')
            ->orderBy('starts_at')
            ->limit($filters['limit'] ?? 60)
            ->get();

        return ['events' => $events, 'source' => $source];
    }

    /**
     * Мероприятие доступно в день D: событие с фиксированным началом — в этот
     * день; место/выставка — если день попадает в период работы.
     */
    private function availableOn(Builder $query, CarbonImmutable $date): void
    {
        $fixed = collect(config('recommender.categories'))->where('kind', 'fixed')->keys()->all();

        $query->where(function (Builder $q) use ($date, $fixed) {
            $q->where(fn (Builder $q) => $q->whereIn('category_group', $fixed)->whereDate('starts_at', $date->toDateString()))
                ->orWhere(fn (Builder $q) => $q->whereNotIn('category_group', $fixed)
                    ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $date->endOfDay()))
                    ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $date->startOfDay())));
        });
    }

    /**
     * Обновить кэш событий из KudaGo. false — источник недоступен.
     */
    public function refreshFromKudaGo(string $cityCode, array $categories = []): bool
    {
        if (Cache::get(self::KUDAGO_DOWN_KEY) || ! isset(City::CITIES_MAP_KG[$cityCode])) {
            return false;
        }

        $kudagoCategories = array_diff($categories, self::PLACE_CATEGORIES);

        // Выбраны только «места» — KudaGo запрашивать незачем, но он жив.
        if ($categories && ! $kudagoCategories) {
            return true;
        }

        $cacheKey = 'kudago:fresh:' . $cityCode . ':' . md5(implode(',', $kudagoCategories));

        if (Cache::get($cacheKey)) {
            return true;
        }

        try {
            $raw = KudaGo::getEvents(
                City::CITIES_MAP_KG[$cityCode],
                implode(',', $kudagoCategories),
                ['age_restriction', 'images', 'short_title', 'dates', 'price', 'is_free'],
                ['actual_since' => now()->getTimestamp()]
            );
        } catch (\Throwable $e) {
            Log::warning('KudaGo unavailable, falling back to demo dataset: ' . $e->getMessage());
            Cache::put(self::KUDAGO_DOWN_KEY, true, now()->addMinutes(10));

            return false;
        }

        $this->cache->upsertFromKudaGo($raw['results'] ?? [], $cityCode);
        Cache::put($cacheKey, true, now()->addMinutes(15));

        return true;
    }

    /**
     * Категории для фильтра: из KudaGo, если доступен, иначе локальный справочник.
     *
     * @return array<int, array{id: int|string, slug: string, name: string}>
     */
    public function categories(): array
    {
        $local = collect(config('recommender.categories'))
            ->map(fn (array $meta, string $slug) => ['id' => $slug, 'slug' => $slug, 'name' => $meta['name']])
            ->values();

        if (Cache::get(self::KUDAGO_DOWN_KEY)) {
            return $local->all();
        }

        try {
            $remote = Cache::remember('kudago:categories', now()->addDay(), fn () => KudaGo::getCategories());
        } catch (\Throwable $e) {
            Log::warning('KudaGo categories unavailable: ' . $e->getMessage());
            Cache::put(self::KUDAGO_DOWN_KEY, true, now()->addMinutes(10));

            return $local->all();
        }

        // KudaGo-категории + наши «места», которых в KudaGo нет.
        return collect($remote)
            ->map(fn (array $c) => ['id' => $c['id'] ?? $c['slug'], 'slug' => $c['slug'], 'name' => $c['name']])
            ->concat($local->whereIn('slug', self::PLACE_CATEGORIES))
            ->unique('slug')
            ->values()
            ->all();
    }
}
