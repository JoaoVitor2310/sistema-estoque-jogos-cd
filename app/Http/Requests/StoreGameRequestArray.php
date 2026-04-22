<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreGameRequestArray extends FormRequest
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
            'games' => 'required|array',
            'games.*.color' => 'nullable',
            'games.*.claim_type' => ['nullable', 'string'],
            'games.*.steamId' => ['string', 'nullable'],
            'games.*.idGamivo' => ['string', 'nullable'],
            'games.*.key_format' => ['nullable', 'string'],
            'games.*.chaveRecebida' => 'required',
            'games.*.nomeJogo' => 'required',
            'games.*.region' => ['string', 'nullable'],
            'games.*.precoJogo' => ['nullable', 'decimal:0,2'],
            'games.*.observacao' => ['string', 'nullable'],
            'games.*.sell_platform' => ['nullable', 'string'],
            'games.*.precoCliente' => ['required', 'decimal:0,2', 'gt:0'],
            'games.*.minimoParaVenda' => ['nullable', 'decimal:0,2'],
            'games.*.minApiGamivo' => ['nullable', 'decimal:0,2'],
            'games.*.maxApiGamivo' => ['nullable', 'decimal:0,2'],
            'games.*.valorPagoTotal' => ['string', 'nullable'],
            'games.*.valorVendido' => ['nullable', 'decimal:0,2', 'gt:0'],
            'games.*.dataAdquirida' => ['required', 'string'],
            'games.*.dataVenda' => ['nullable', 'string'],
            'games.*.dataVendida' => ['nullable', 'string'],
            'games.*.dataExpiracao' => ['nullable', 'string'],
            'games.*.perfilOrigem' => ['required', 'string'],
            'games.*.email' => ['nullable', 'string'],
            'games.*.qtdTF2' => ['required', 'decimal:0,2', 'gt:0'],
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
