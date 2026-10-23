<?php

namespace App\Http\Requests\Recommendation;

use App\Models\UserInteraction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InteractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(UserInteraction::ACTIONS)],
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
            'meta' => ['nullable', 'array'],
        ];
    }
}
