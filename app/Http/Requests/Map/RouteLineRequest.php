<?php

namespace App\Http\Requests\Map;

use Illuminate\Foundation\Http\FormRequest;

class RouteLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'points' => ['required', 'array', 'min:2'],
            'points.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'points.*.lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
