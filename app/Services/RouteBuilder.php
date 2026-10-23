<?php

namespace App\Services;

use App\Models\Event;
use App\Services\Routing\RouteOptimizer;

/**
 * Построение маршрута по фиксированному набору выбранных пользователем
 * мероприятий. Сама оптимизация (матрица затрат, временные окна,
 * Хелд–Карп / 2-opt) — в {@see RouteOptimizer}.
 */
class RouteBuilder
{
    public function __construct(private readonly RouteOptimizer $optimizer)
    {
    }

    /**
     * @param int[] $eventIds
     * @param array $options см. RouteOptimizer::optimize()
     */
    public function build(array $eventIds, ?float $budget = null, array $options = []): array
    {
        $events = Event::whereIn('id', $eventIds)->get()->keyBy('id');
        $ordered = collect($eventIds)->map(fn ($id) => $events->get($id))->filter()->values();

        return $this->optimizer->optimize($ordered, [...$options, 'budget' => $budget]);
    }
}
