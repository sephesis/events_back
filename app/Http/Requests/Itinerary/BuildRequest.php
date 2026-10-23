<?php

namespace App\Http\Requests\Itinerary;

use Illuminate\Foundation\Http\FormRequest;

class BuildRequest extends FormRequest
{
    use RouteOptionsRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_ids' => ['required', 'array', 'min:1', 'max:30'],
            'event_ids.*' => ['integer', 'distinct', 'exists:events,id'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            ...$this->routeOptionRules(),
        ];
    }
}
