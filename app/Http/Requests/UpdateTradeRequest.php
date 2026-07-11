<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'supplierUrl' => ['nullable', 'string'],
            'date' => ['nullable', 'string'],
            'tf2Qty' => ['nullable', 'decimal:0,2'],
            'games' => ['nullable', 'array'],
            'message_sent' => ['nullable', 'boolean'],
        ];
    }
}
