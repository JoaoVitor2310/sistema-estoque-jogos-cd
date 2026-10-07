<?php

namespace App\Http\Requests;

use App\Domain\Enums\PurchaseChannel;
use App\Domain\Enums\TradeCurrency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'purchaseChannel' => ['nullable', Rule::enum(PurchaseChannel::class)],
            'supplierUrl' => ['nullable', 'string'],
            'date' => ['nullable', 'string'],
            'currency' => ['nullable', Rule::enum(TradeCurrency::class)],
            'amount' => ['nullable', 'decimal:0,2'],
            'message_sent' => ['nullable', 'boolean'],
        ];
    }
}
