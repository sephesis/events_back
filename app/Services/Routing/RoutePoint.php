<?php

namespace App\Services\Routing;

use App\Models\Event;

/**
 * Вершина задачи маршрутизации: мероприятие или стартовая точка.
 *
 * Время — в минутах от полуночи дня маршрута. [earliest, latest] — окно,
 * в которое допустимо НАЧАТЬ посещение; service — длительность посещения.
 */
final class RoutePoint
{
    public function __construct(
        public readonly float $lat,
        public readonly float $lng,
        public readonly int $earliest,
        public readonly int $latest,
        public readonly int $service,
        public readonly float $price = 0.0,
        public readonly ?Event $event = null,
    ) {
    }

    public static function start(float $lat, float $lng, int $time): self
    {
        return new self($lat, $lng, $time, $time, 0);
    }
}
