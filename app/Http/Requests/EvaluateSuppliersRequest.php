<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EvaluateSuppliersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'games' => ['required', 'array', 'min:1'],
            'games.*.name' => ['required', 'string', 'max:255'],
            'games.*.price_euro' => ['required', 'numeric', 'min:0'],
            'games.*.popularity' => ['required', 'integer', 'min:0'],
            'games.*.region' => ['required', 'string'],
        ];
    }
}
