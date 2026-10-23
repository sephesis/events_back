# Досуг — API (Laravel 12 + Sail)

Бэкенд рекомендательной системы для планирования персонализированных маршрутов
(прототип к ВКР). Фронтенд — репозиторий `events_front`.

## Запуск

```bash
cp .env.example .env            # порты уже сдвинуты: 8080 занят другими проектами
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html \
  laravelsail/php84-composer:latest composer install --ignore-platform-reqs
./vendor/bin/sail up -d mysql redis mailpit laravel.test
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed   # пользователи + демо-набор мероприятий
```

| Сервис | Порт хоста |
|---|---|
| API | http://localhost:8081 (`/up` — health) |
| MySQL | 3307 |
| Redis | 6380 |
| Mailpit | 8026 |

Тестовые пользователи: `admin@admin.com` / `admin@admin.com`, `simple@simple.com` / `simple@simple.com`.

Тесты: `./vendor/bin/sail artisan test`.

### Данные

Основной источник — KudaGo API. Если он недоступен (сейчас отвечает 403 с этой сети),
используется демо-набор (`source = demo`): реальные площадки Москвы и Петербурга,
сгенерированные мероприятия на 14 дней вперёд и синтетические пользователи
(`*@demo.local`), из маршрутов которых строится общая матрица цепи Маркова.
Даты демо-событий сдвигаются командой `./vendor/bin/sail artisan demo:refresh`.

## Алгоритмы (глава 1 ВКР)

| Задача | Реализация |
|---|---|
| Рекомендация следующей точки | `Recommendation/MarkovChainService` — цепь Маркова 1-го порядка над категориями, сглаживание Лапласа, затухание по давности, смешивание личной и общей матриц `λᵢ = nᵢ/(nᵢ+β)` |
| Итоговая оценка | `RecommendationService` — `Score(e,u) = w1·P_M + w2·S_I + w3·S_D + w4·S_T + w5·S_B`; S_I включает item-based CF (R_user гибридной модели) |
| Матрица затрат | `Routing/DistanceMatrixService` — OSRM `/table` (пешеходный граф OSM), фолбэк haversine ×1.3 |
| Порядок посещения | `Routing/HeldKarpSolver` (точно, ≤ 12 точек, временные окна, необязательные точки), `Routing/HeuristicSolver` (ближайший сосед + 2-opt + Or-opt) |
| Двухэтапный конвейер | `ItineraryPlanner` — история → Марков → Score → проверка допустимости → оптимизация |

Веса и параметры — `config/recommender.php`.

## API (`/api/v1`)

| Метод | Путь | Описание |
|---|---|---|
| GET | `events?city=&categories=&date=&budget=&q=` | мероприятия (KudaGo / демо) |
| GET | `events/{id}`, `categories` | карточка, справочник категорий |
| POST | `recommendations` | ранжирование по Score с разбивкой и объяснениями (токен — необязателен) |
| GET | `recommendations/model` | матрица переходов цепи Маркова |
| POST | `itineraries/build` | оптимальный порядок для выбранных точек |
| POST | `itineraries/plan` | автоматический персональный маршрут |
| POST | `route-line` | линия маршрута по улицам (OSRM) |
| auth | `auth/register`, `auth/login`, `auth/logout`, `auth/me` | Sanctum-токены |
| auth | `itineraries` (CRUD), `itineraries/recommendations` | сохранённые маршруты |
| auth | `interactions` | журнал действий для модели |
| auth | `profile` (GET/PUT/DELETE), `profile/history` (DELETE) | интересы, бюджет, удаление |
