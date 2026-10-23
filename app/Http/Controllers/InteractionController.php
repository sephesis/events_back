<?php

namespace App\Http\Controllers;

use App\Http\Requests\Recommendation\InteractionRequest;
use App\Models\Event;
use Illuminate\Http\JsonResponse;

/**
 * Сохранение действий пользователя — звено цикла
 * «Рекомендация → Действие → Сохранение действия → Обновление модели».
 */
class InteractionController extends Controller
{
    public function store(InteractionRequest $request): JsonResponse
    {
        $eventId = $request->validated('event_id');

        $interaction = $request->user()->interactions()->create([
            'event_id' => $eventId,
            'category_group' => $eventId ? Event::find($eventId)?->category_group : null,
            'action' => $request->validated('action'),
            'meta' => $request->validated('meta'),
        ]);

        return response()->json(['id' => $interaction->id], 201);
    }
}
