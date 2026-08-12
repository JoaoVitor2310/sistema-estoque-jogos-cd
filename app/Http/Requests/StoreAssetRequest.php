<?php

namespace App\Http\Requests;

use App\Services\External\CurrencyConversionService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'string',
            'price_euro' => ['required', 'decimal:0,3'],
            'price_dollar' => ['required', 'decimal:0,3'],
            'price_brl' => ['required', 'decimal:0,3'],
            // Moedas aceitas derivam do mapa do serviço de conversão: uma lista
            // escrita à mão aqui aceitaria moeda que o conversor não conhece.
            'currentCurrency' => [
                'nullable',
                'string',
                Rule::in(array_keys(CurrencyConversionService::PRICE_FIELD_BY_CURRENCY)),
            ],
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
