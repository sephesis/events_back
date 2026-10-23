<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'interests' => ['sometimes', 'array'],
            'interests.*' => ['string', Rule::in(array_keys(config('recommender.categories')))],
            'default_budget' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'home_city_code' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
