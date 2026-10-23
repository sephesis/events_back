<?php

/*
|--------------------------------------------------------------------------
| Рекомендательная система и маршрутизация
|--------------------------------------------------------------------------
|
| Параметры моделей из главы 1 ВКР:
|  - итоговая оценка мероприятия
|      Score(e,u) = w1·P_M(e|u) + w2·S_I(e,u) + w3·S_D(e) + w4·S_T(e) + w5·S_B(e)
|  - цепь Маркова первого порядка над категориями мероприятий
|  - оптимизация порядка посещения (Хелд–Карп / ближайший сосед + 2-opt)
|
*/

return [

    // Весовые коэффициенты w1..w5 итоговой формулы Score(e,u).
    'weights' => [
        'markov' => (float) env('REC_W_MARKOV', 0.35),   // w1 · P_M(e|u)
        'interest' => (float) env('REC_W_INTEREST', 0.25), // w2 · S_I(e,u)
        'distance' => (float) env('REC_W_DISTANCE', 0.15), // w3 · S_D(e)
        'time' => (float) env('REC_W_TIME', 0.15),       // w4 · S_T(e)
        'budget' => (float) env('REC_W_BUDGET', 0.10),   // w5 · S_B(e)
    ],

    // Внутренние веса S_I: явные интересы профиля, контентное сходство
    // с историей и item-based коллаборативная составляющая (R_user).
    'interest_mix' => [
        'profile' => 0.5,
        'history' => 0.3,
        'collaborative' => 0.2,
    ],

    'markov' => [
        // Аддитивное сглаживание Лапласа для матрицы переходов.
        'smoothing_alpha' => 0.5,
        // λ_i = n_i / (n_i + β): насколько доверять личной матрице против общей.
        'personal_beta' => 5.0,
        // Полупериод затухания веса старых действий, дней.
        'recency_half_life_days' => 30.0,
        // Разрыв между действиями, после которого начинается новая сессия, минут.
        'session_gap_minutes' => 120,
        // Вес перехода в зависимости от источника последовательности.
        'source_weights' => [
            'itinerary' => 1.5,     // сохранённый маршрут — самый сильный сигнал
            'add_to_route' => 1.0,
            'details' => 0.4,
        ],
        'global_cache_seconds' => 600,
    ],

    // Характерное расстояние пешей доступности S_D = exp(-d / D0), км.
    'walk_distance_scale_km' => 3.0,

    // Штраф к S_I за мероприятия, которые пользователь недавно удалял/отклонял.
    'negative_feedback_factor' => 0.3,

    'routing' => [
        // haversine | osrm — источник матрицы затрат.
        'matrix_provider' => env('ROUTING_MATRIX_PROVIDER', 'osrm'),
        'osrm_table_url' => env('OSRM_TABLE_URL', 'https://routing.openstreetmap.de/routed-foot/table/v1/foot/'),
        'osrm_timeout' => 5.0,
        // Поправка «прямая → реальный пеший путь» для haversine-фолбэка.
        'detour_factor' => 1.3,
        'walk_speed_kmh' => 4.5,
        // До какого числа точек применять точный Хелд–Карп, дальше — эвристика.
        'exact_max_points' => 12,
        // Сколько вариантов маршрута возвращать.
        'max_variants' => 3,
        // Опоздание на мероприятие с фиксированным началом, которое ещё допустимо, мин.
        'fixed_start_slack_min' => 10,
    ],

    // Время работы мест по умолчанию, если у события не указано своё.
    'default_opening_hours' => ['10:00', '22:00'],

    /*
    | Категории (slug совпадает с KudaGo, чтобы фильтр работал для обоих
    | источников). kind:
    |   fixed — начинается в конкретное время (концерт, спектакль);
    |   open  — можно прийти в любое время работы (выставка, парк, кафе).
    | duration — длительность посещения по умолчанию, мин.
    */
    'categories' => [
        'concert' => ['name' => 'Концерты', 'kind' => 'fixed', 'duration' => 120],
        'theater' => ['name' => 'Спектакли', 'kind' => 'fixed', 'duration' => 150],
        'stand-up' => ['name' => 'Стендап', 'kind' => 'fixed', 'duration' => 90],
        'cinema' => ['name' => 'Кинопоказы', 'kind' => 'fixed', 'duration' => 120],
        'party' => ['name' => 'Вечеринки', 'kind' => 'fixed', 'duration' => 180],
        'education' => ['name' => 'Лекции и мастер-классы', 'kind' => 'fixed', 'duration' => 90],
        'quest' => ['name' => 'Квесты', 'kind' => 'fixed', 'duration' => 60],
        'tour' => ['name' => 'Экскурсии', 'kind' => 'fixed', 'duration' => 90],
        'exhibition' => ['name' => 'Выставки', 'kind' => 'open', 'duration' => 90],
        'festival' => ['name' => 'Фестивали и маркеты', 'kind' => 'open', 'duration' => 120],
        'entertainment' => ['name' => 'Развлечения', 'kind' => 'open', 'duration' => 90],
        'kids' => ['name' => 'Детям', 'kind' => 'open', 'duration' => 90],
        'park' => ['name' => 'Парки и прогулки', 'kind' => 'open', 'duration' => 60],
        'cafe' => ['name' => 'Кафе и рестораны', 'kind' => 'open', 'duration' => 60],
        'bar' => ['name' => 'Бары', 'kind' => 'open', 'duration' => 90],
    ],

    'default_category' => ['name' => 'Другое', 'kind' => 'open', 'duration' => 90],
];
