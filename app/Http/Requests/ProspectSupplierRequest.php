<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProspectSupplierRequest extends FormRequest
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
            'supplier_steam_id' => ['required', 'string'],
            'list_code' => ['nullable', 'string'],
            'games' => ['required', 'array', 'min:1'],
            'games.*.name' => ['required', 'string', 'max:255'],
            'games.*.price_euro' => ['required', 'numeric', 'min:0'],
            'games.*.popularity' => ['required', 'integer', 'min:0'],
            'games.*.region' => ['nullable', 'string'],
            'games.*.gamivo_id' => ['nullable', 'string'],
        ];
    }
}
