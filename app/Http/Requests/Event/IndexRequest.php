<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

class IndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'city' => ['required', 'string'],
            // categories — список slug через запятую; areas — старое имя параметра.
            'categories' => ['nullable', 'string'],
            'areas' => ['nullable', 'string'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return string[] */
    public function categories(): array
    {
        $raw = (string) ($this->validated('categories') ?? $this->validated('areas') ?? '');

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function budget(): ?float
    {
        $budget = $this->validated('budget') ?? $this->validated('price');

        return $budget ? (float) $budget : null;
    }
}
