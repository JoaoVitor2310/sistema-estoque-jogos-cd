<?php

namespace App\Http\Requests;

use App\Domain\Enums\TradeCurrency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Ausente equivale a TF2: quem ainda não envia o campo segue como antes.
            'offer_currency' => ['nullable', Rule::enum(TradeCurrency::class)],
            'games' => ['required', 'array', 'min:1'],
            'games.*.name' => ['required', 'string', 'max:255'],
            'games.*.market_price_euro' => ['required', 'numeric', 'min:0'],
            'games.*.popularity' => ['required', 'integer', 'min:0'],
            'games.*.region' => ['nullable', 'string'],
            'games.*.gamivo_id' => ['nullable', 'string'],
        ];
    }
}
