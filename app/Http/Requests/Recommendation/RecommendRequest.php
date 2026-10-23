<?php

namespace App\Http\Requests\Recommendation;

use Illuminate\Foundation\Http\FormRequest;

class RecommendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'city_code' => ['required', 'string'],
            // Текущий черновик маршрута: последняя точка задаёт состояние цепи Маркова и S_D.
            'current_event_ids' => ['nullable', 'array'],
            'current_event_ids.*' => ['integer', 'exists:events,id'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'time_from' => ['nullable', 'date_format:H:i'],
            'time_to' => ['nullable', 'date_format:H:i'],
            'start' => ['nullable', 'array'],
            'start.lat' => ['required_with:start', 'numeric'],
            'start.lng' => ['required_with:start', 'numeric'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
