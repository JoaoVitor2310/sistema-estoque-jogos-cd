<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ImportTradeKeysRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Regras na mesma ordem das colunas do TradeCalculator (frontend).
     */
    public function rules(): array
    {
        return [
            'games' => ['required', 'array', 'min:1'],
            'games.*.acquired_at' => ['required', 'string'],
            'games.*.market_price' => ['required', 'numeric', 'gt:0'],
            'games.*.supplier_url' => ['required', 'string'],
            'games.*.tf2_quantity' => ['required', 'numeric', 'gt:0'],
            'games.*.bundle' => ['nullable', 'string'],
            'games.*.expires_at' => ['nullable', 'string'],
            'games.*.popularity' => ['nullable', 'string'],
            'games.*.region' => ['nullable', 'string'],
            'games.*.key_code' => ['required', 'string'],
            'games.*.game_name' => ['required', 'string'],
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'statusCode' => 422,
            'message' => 'Dados inválidos',
            'errors' => $validator->errors(),
            'data' => [],
        ], 422));
    }
}
