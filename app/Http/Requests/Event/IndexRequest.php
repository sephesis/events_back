<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // implement more accurate
    }

    public function rules(): array
    {
        return [
            'city' => ['required', 'string'],
            'areas' => ['string'],
        ];
    }
}