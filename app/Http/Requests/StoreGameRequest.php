<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreGameRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'color' => 'nullable',
            'claim_type' => ['nullable', 'string'],
            'steamId' => ['string', 'nullable'],
            'idGamivo' => ['string', 'nullable'],
            'key_format' => ['nullable', 'string'],
            'chaveRecebida' => 'required',
            'nomeJogo' => 'required',
            'region' => ['string', 'nullable'],
            'precoJogo' => ['nullable', 'decimal:0,2'],
            'observacao' => ['string', 'nullable'],
            'sell_platform' => ['nullable', 'string'],
            'precoCliente' => ['required', 'decimal:0,2', 'gt:0'],
            'minimoParaVenda' => ['nullable', 'decimal:0,2'],
            'minApiGamivo' => ['nullable', 'decimal:0,2'],
            'maxApiGamivo' => ['nullable', 'decimal:0,2'],
            'valorPagoTotal' => ['string', 'nullable'],
            'valorVendido' => ['nullable', 'decimal:0,2', 'gt:0'],
            'dataAdquirida' => ['required', 'string'],
            'dataVenda' => ['nullable', 'string'],
            'dataVendida' => ['nullable', 'string'],
            'dataExpiracao' => ['nullable', 'string'],
            'perfilOrigem' => ['required', 'string'],
            'email' => ['nullable', 'string'],
            'qtdTF2' => ['nullable', 'decimal:0,2', 'gt:0'],
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
