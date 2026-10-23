<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Itinerary;
use App\Models\User;
use App\Models\UserInteraction;
use App\Services\EventSourceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Демо-набор данных на случай недоступности KudaGo.
 *
 *  - Мероприятия на реальных площадках Москвы и Санкт-Петербурга, даты
 *    генерируются относительно сегодняшнего дня на 14 дней вперёд.
 *    Генерация детерминирована (фиксированный seed), события обновляются
 *    по стабильному external_id — повторный запуск (`php artisan demo:refresh`)
 *    сдвигает даты, не ломая сохранённые маршруты пользователей.
 *  - Синтетические пользователи с историей маршрутов: из них строится
 *    общая матрица переходов цепи Маркова (холодный старт для новых
 *    пользователей). Это явно синтетические данные (домен demo.local).
 */
class DemoDataSeeder extends Seeder
{
    private const DAYS_AHEAD = 14;

    private const TITLES = [
        'concert' => ['Джазовый вечер с квартетом «Синкопа»', 'Камерный оркестр: Вивальди и Пьяццолла', 'Инди-концерт «Северный ветер»', 'Органная музыка при свечах', 'Симфонические хиты из кино', 'Фортепианный вечер: Рахманинов', 'Акустический сет «Тёплые лампы»', 'Электронная музыка: живой сет'],
        'theater' => ['«Вишнёвый сад»', '«Ревизор»', '«Мастер и Маргарита»', '«Гамлет»', '«Чайка»', 'Балет «Лебединое озеро»', '«Евгений Онегин»', 'Иммерсивный спектакль «Сон в летнюю ночь»'],
        'stand-up' => ['Открытый микрофон', 'Большой стендап-концерт', 'Импровизация: шоу без сценария', 'Стендап на английском', 'Проверка нового материала'],
        'cinema' => ['Ретроспектива Тарковского: «Солярис»', 'Ночь короткого метра', 'Классика нуара: «Мальтийский сокол»', 'Документальное кино о городе', 'Фестиваль авторской анимации', 'Кинопоказ «Амели»'],
        'party' => ['Вечеринка «Диско 80-х»', 'Техно до утра', 'Вечеринка в стиле «Гэтсби»', 'Сайлент-диско', 'Ночь винила'],
        'education' => ['Лекция «Как смотреть современное искусство»', 'Мастер-класс по керамике', 'Лекция об архитектуре конструктивизма', 'Воркшоп по скетчингу', 'Книжный клуб: обсуждение романа', 'Лекция о космосе для взрослых', 'Мастер-класс по каллиграфии'],
        'quest' => ['Квест «Побег из лаборатории»', 'Хоррор-квест «Заброшенная больница»', 'Детективный квест «Шерлок»', 'Квест «Ограбление банка»'],
        'tour' => ['Пешая экскурсия по старому центру', 'Экскурсия «Тайны дворов»', 'Архитектурная прогулка', 'Ночная экскурсия с историями', 'Экскурсия по фасадам модерна'],
        'exhibition' => ['Выставка «Свет и тень»', 'Фотовыставка «Город 70-х»', 'Импрессионисты: новые имена', 'Медиавыставка «Код»', 'Русский авангард', 'Выставка современного дизайна', 'Ретроспектива графики', 'Скульптура XX века'],
        'festival' => ['Маркет локальных дизайнеров', 'Гастрофестиваль «Вкус осени»', 'Фестиваль уличной еды', 'Винтажный маркет', 'Книжная ярмарка', 'Маркет керамики и хендмейда'],
        'entertainment' => ['Каток под открытым небом', 'Колесо обозрения', 'Иммерсивное шоу', 'Вечер настольных игр'],
        'kids' => ['Семейная программа «Юный исследователь»', 'Мастер-класс для детей', 'Интерактивная экспозиция для семей'],
    ];

    private const START_TIMES = [
        'concert' => ['19:00', '19:30', '20:00'],
        'theater' => ['19:00', '19:30'],
        'stand-up' => ['20:00', '21:00'],
        'cinema' => ['15:00', '18:30', '21:00'],
        'party' => ['22:00', '23:00'],
        'education' => ['12:00', '15:00', '19:00'],
        'quest' => ['11:00', '14:00', '17:00', '20:00'],
        'tour' => ['11:00', '13:00', '16:00', '21:00'],
    ];

    private const PRICES = [
        'concert' => [800, 3500], 'theater' => [1000, 5000], 'stand-up' => [500, 1500],
        'cinema' => [350, 700], 'party' => [800, 2000], 'education' => [0, 1200],
        'quest' => [2500, 4000], 'tour' => [0, 1200], 'exhibition' => [0, 800],
        'festival' => [0, 0], 'entertainment' => [300, 900], 'kids' => [400, 900],
        'park' => [0, 0], 'cafe' => [600, 2500], 'bar' => [800, 2000],
    ];

    private const DESCRIPTIONS = [
        'concert' => 'Живая музыка в атмосферном зале. Подойдёт для вечера вдвоём или с друзьями.',
        'theater' => 'Спектакль в постановке труппы театра. Рекомендуем прийти за 20 минут до начала.',
        'stand-up' => 'Комики пробуют новый материал. Возможна ненормативная лексика.',
        'cinema' => 'Показ на большом экране с коротким вступительным словом.',
        'party' => 'Танцы, диджеи и хорошая компания до позднего вечера.',
        'education' => 'Небольшая группа, все материалы включены. Подходит для новичков.',
        'quest' => 'Команда от 2 до 5 человек, 60 минут на прохождение.',
        'tour' => 'Прогулка с гидом по интересным местам района. Удобная обувь обязательна.',
        'exhibition' => 'Временная выставка с аудиогидом и экспликациями.',
        'festival' => 'Маркет, еда и музыка. Вход свободный.',
        'entertainment' => 'Развлечение для компании и пар.',
        'kids' => 'Программа для детей 6–12 лет и их родителей.',
        'park' => 'Прогулочная зона, места для отдыха и кофе навынос.',
        'cafe' => 'Кафе для перерыва между точками маршрута. Цена — средний чек.',
        'bar' => 'Бар для завершения вечера. Цена — средний чек.',
    ];

    /**
     * Типичные сценарии досуга для синтетических пользователей
     * (последовательности категорий внутри одного маршрута).
     */
    private const PATTERNS = [
        ['exhibition', 'cafe', 'concert'],
        ['park', 'cafe', 'cinema'],
        ['tour', 'cafe', 'theater'],
        ['exhibition', 'park', 'bar'],
        ['education', 'cafe', 'stand-up'],
        ['festival', 'park', 'bar'],
        ['park', 'exhibition', 'cafe'],
        ['theater', 'bar'],
        ['concert', 'bar'],
        ['kids', 'park', 'cafe'],
        ['exhibition', 'cafe', 'theater'],
        ['tour', 'exhibition', 'cafe'],
    ];

    /** Персоны: какие сценарии пользователь выбирает чаще. */
    private const PERSONAS = [
        [0, 6, 10, 11], [1, 3, 5], [2, 7, 10], [4, 8], [9, 1, 6], [0, 3, 8], [5, 3, 1], [2, 11, 7],
        [0, 10, 4], [6, 1, 9], [8, 7, 5], [11, 2, 0],
    ];

    public function run(): void
    {
        mt_srand(20261023);
        $today = CarbonImmutable::today();
        $venues = require __DIR__ . '/data/demo_venues.php';
        $eventsByCity = [];

        foreach ($venues as $cityCode => $cityVenues) {
            foreach ($cityVenues as $venueIdx => $venue) {
                foreach ($venue[4] as $category) {
                    foreach ($this->eventsForVenue($cityCode, $venueIdx, $venue, $category, $today) as $attributes) {
                        $event = Event::updateOrCreate(
                            ['source' => EventSourceService::SOURCE_DEMO, 'external_id' => $attributes['external_id']],
                            $attributes
                        );
                        $eventsByCity[$cityCode][$category][] = $event->id;
                    }
                }
            }
        }

        $this->seedSyntheticUsers($eventsByCity);

        Cache::forget('markov:global');
        Cache::forget('kudago:down');
    }

    private function eventsForVenue(string $cityCode, int $venueIdx, array $venue, string $category, CarbonImmutable $today): array
    {
        [$name, $address, $lat, $lng, , $hours] = $venue;
        $kind = config('recommender.categories.' . $category . '.kind');
        $base = [
            'source' => EventSourceService::SOURCE_DEMO,
            'city_code' => $cityCode,
            'category_group' => $category,
            'place_name' => $name,
            'address' => $address,
            'lat' => $lat,
            'lng' => $lng,
            'description' => self::DESCRIPTIONS[$category],
            'fetched_at' => now(),
        ];
        $prefix = substr($cityCode, 0, 2) . '-' . $venueIdx . '-' . $category;

        // Места: одна «постоянная» запись с часами работы.
        if (in_array($category, EventSourceService::PLACE_CATEGORIES, true)) {
            [$opens, $closes] = $hours ?? ($category === 'bar' ? ['18:00', '02:00'] : ['09:00', '23:00']);

            return [[
                ...$base,
                ...$this->price($category),
                'external_id' => $prefix,
                'title' => $category === 'park' ? 'Прогулка: ' . $name : $name,
                'short_title' => $name,
                'tagline' => config('recommender.categories.' . $category . '.name'),
                'opens_at' => $opens,
                'closes_at' => $closes,
                'images' => $this->image($prefix),
                'starts_at' => null,
                'ends_at' => null,
            ]];
        }

        $title = fn () => self::TITLES[$category][mt_rand(0, count(self::TITLES[$category]) - 1)];
        $result = [];

        if ($kind === 'fixed') {
            $sessions = mt_rand(2, 3);

            for ($n = 0; $n < $sessions; $n++) {
                $times = self::START_TIMES[$category];
                $startsAt = CarbonImmutable::parse($today->addDays(mt_rand(0, self::DAYS_AHEAD - 1))->toDateString() . ' ' . $times[mt_rand(0, count($times) - 1)]);
                $duration = config('recommender.categories.' . $category . '.duration');

                $result[] = [
                    ...$base,
                    ...$this->price($category),
                    'external_id' => $prefix . '-' . $n,
                    'title' => $title(),
                    'tagline' => $name,
                    'starts_at' => $startsAt,
                    'ends_at' => $startsAt->addMinutes($duration),
                    'opens_at' => null,
                    'closes_at' => null,
                    'images' => $this->image($prefix . $n),
                    'age_restriction' => in_array($category, ['stand-up', 'party'], true) ? '18+' : null,
                ];
            }

            return $result;
        }

        // open: выставки — длительный период, фестивали — выходные, остальное — постоянно.
        [$opens, $closes] = $hours ?? config('recommender.default_opening_hours');
        [$startsAt, $endsAt] = match ($category) {
            'exhibition' => [$today->subDays(mt_rand(0, 30)), $today->addDays(mt_rand(14, 60))->endOfDay()],
            'festival' => (function () use ($today) {
                $day = $today->addDays(mt_rand(0, self::DAYS_AHEAD - 2));

                return [$day, $day->addDay()->endOfDay()];
            })(),
            default => [null, null],
        };

        if ($category === 'festival') {
            [$opens, $closes] = ['12:00', '21:00'];
        }

        return [[
            ...$base,
            ...$this->price($category),
            'external_id' => $prefix . '-0',
            'title' => $title(),
            'tagline' => $name,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'opens_at' => $opens,
            'closes_at' => $closes,
            'images' => $this->image($prefix),
        ]];
    }

    private function price(string $category): array
    {
        [$min, $max] = self::PRICES[$category];

        if ($max === 0 || ($min === 0 && mt_rand(1, 100) <= 35)) {
            return ['price_min' => null, 'price_max' => null, 'is_free' => true];
        }

        $price = (int) (round(mt_rand(max($min, 100), $max) / 50) * 50);

        return ['price_min' => $price, 'price_max' => $price + 50 * mt_rand(0, 20), 'is_free' => false];
    }

    private function image(string $seed): array
    {
        return [['image' => 'https://picsum.photos/seed/' . substr(md5($seed), 0, 10) . '/640/420']];
    }

    private function seedSyntheticUsers(array $eventsByCity): void
    {
        $cities = array_keys($eventsByCity);

        foreach (self::PERSONAS as $i => $persona) {
            $user = User::updateOrCreate(
                ['email' => 'persona' . ($i + 1) . '@demo.local'],
                ['name' => 'Демо-пользователь ' . ($i + 1), 'password' => Hash::make(str()->random(32))]
            );

            Itinerary::where('user_id', $user->id)->delete();
            $user->interactions()->delete();

            $count = mt_rand(3, 6);

            for ($n = 0; $n < $count; $n++) {
                $city = $cities[($i + $n) % count($cities)];
                $pattern = self::PATTERNS[$persona[mt_rand(0, count($persona) - 1)]];
                $createdAt = now()->subDays(mt_rand(1, 60))->subMinutes(mt_rand(0, 600));
                $eventIds = [];

                foreach ($pattern as $category) {
                    $pool = $eventsByCity[$city][$category] ?? [];

                    if ($pool) {
                        $eventIds[] = $pool[mt_rand(0, count($pool) - 1)];
                    }
                }

                $eventIds = array_values(array_unique($eventIds));

                if (count($eventIds) < 2) {
                    continue;
                }

                $itinerary = new Itinerary(['city_code' => $city, 'title' => 'Маршрут ' . ($n + 1)]);
                $itinerary->user_id = $user->id;
                $itinerary->created_at = $createdAt;
                $itinerary->updated_at = $createdAt;
                $itinerary->save();

                foreach ($eventIds as $position => $eventId) {
                    $itinerary->stops()->create(['event_id' => $eventId, 'position' => $position + 1]);

                    UserInteraction::create([
                        'user_id' => $user->id,
                        'event_id' => $eventId,
                        'category_group' => Event::find($eventId)->category_group,
                        'action' => UserInteraction::ACTION_ADD,
                    ])->forceFill(['created_at' => $createdAt->copy()->subMinutes(10 - $position)])->save();
                }
            }
        }
    }
}
