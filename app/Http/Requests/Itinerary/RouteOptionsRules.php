<?php

namespace App\Http\Requests\Itinerary;

/**
 * Общие правила для параметров построения маршрута: стартовая точка,
 * дата и временное окно.
 */
trait RouteOptionsRules
{
    protected function routeOptionRules(): array
    {
        return [
            'start' => ['nullable', 'array'],
            'start.lat' => ['required_with:start', 'numeric', 'between:-90,90'],
            'start.lng' => ['required_with:start', 'numeric', 'between:-180,180'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'return_to_start' => ['nullable', 'boolean'],
        ];
    }

    public function routeOptions(): array
    {
        return [
            'start' => $this->validated('start'),
            'date' => $this->validated('date'),
            'start_time' => $this->validated('start_time'),
            'end_time' => $this->validated('end_time'),
            'return_to_start' => (bool) $this->validated('return_to_start'),
        ];
    }
}
