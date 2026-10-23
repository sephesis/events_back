<?php

namespace App\Http\Requests\Itinerary;

use Illuminate\Foundation\Http\FormRequest;

class PlanRequest extends FormRequest
{
    use RouteOptionsRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'city_code' => ['required', 'string'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'stops' => ['nullable', 'integer', 'min:1', 'max:8'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string'],
            'exclude_ids' => ['nullable', 'array'],
            'exclude_ids.*' => ['integer'],
            ...$this->routeOptionRules(),
        ];
    }
}
