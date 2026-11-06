<?php

namespace App\Http\Requests;

use App\Domain\Enums\TradeCurrency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConvertTradeCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'currency' => ['required', Rule::enum(TradeCurrency::class)],
        ];
    }

    public function targetCurrency(): TradeCurrency
    {
        return TradeCurrency::from($this->validated('currency'));
    }
}
