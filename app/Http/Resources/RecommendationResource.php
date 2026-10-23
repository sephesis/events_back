<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Результат ранжирования: мероприятие + Score(e,u) с разбивкой
 * по компонентам формулы и текстовыми объяснениями.
 *
 * @property array $resource
 */
class RecommendationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'event' => new EventResource($this->resource['event']),
            'score' => $this->resource['score'],
            'markov_probability' => $this->resource['markov_probability'],
            'components' => $this->resource['components'],
            'reasons' => $this->resource['reasons'],
        ];
    }
}
