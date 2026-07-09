<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreListTradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_steam_id' => ['nullable', 'string'],
            'title' => ['nullable', 'string', 'max:255'],
            'list_code' => ['nullable', 'string'],
            'games' => ['required', 'array', 'min:1'],
            'games.*.name' => ['required', 'string'],
            'games.*.price_euro' => ['required', 'numeric'],
            'games.*.popularity' => ['required', 'integer'],
            'games.*.region' => ['nullable', 'string'],
        ];
    }
}
