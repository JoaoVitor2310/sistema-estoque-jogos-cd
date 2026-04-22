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
            'tipo_reclamacao_id' => ['integer', 'exists:tipo_reclamacao,id'],        // 6.6: FK dinâmica
            'steamId' => ['string', 'nullable'],
            'idGamivo' => ['string', 'nullable'],
            'tipo_formato_id' => ['integer', 'exists:tipo_formato,id'],            // 6.6: FK dinâmica
            'chaveRecebida' => 'required',
            'nomeJogo' => 'required',
            'region' => ['string', 'nullable'],
            'precoJogo' => ['nullable', 'decimal:0,2'],
            'notaMetacritic' => 'integer|min:0|max:100',
            'isSteam' => ['boolean', 'nullable'],
            'observacao' => ['string', 'nullable'],
            'id_leilao_g2a' => ['integer', 'exists:tipo_leilao,id'],             // 6.6: FK dinâmica
            'id_leilao_gamivo' => ['integer', 'exists:tipo_leilao,id'],             // 6.6: FK dinâmica
            'id_leilao_kinguin' => ['integer', 'exists:tipo_leilao,id'],             // 6.6: FK dinâmica
            'id_plataforma' => ['integer', 'exists:plataforma,id'],              // 6.6: FK dinâmica
            'precoCliente' => ['required', 'decimal:0,2', 'gt:0'],             // 6.5: preço > 0
            'minimoParaVenda' => ['nullable', 'decimal:0,2'],
            'minApiGamivo' => ['nullable', 'decimal:0,2'],
            'maxApiGamivo' => ['nullable', 'decimal:0,2'],
            'chaveEntregue' => ['string', 'nullable'],
            'valorPagoTotal' => ['string', 'nullable'],
            'vendido' => 'boolean',
            'leiloes' => 'integer|min:0',
            'quantidade' => 'integer|min:0',
            'devolucoes' => 'boolean',
            'valorVendido' => ['nullable', 'decimal:0,2', 'gt:0'],             // 6.5: valor vendido > 0 se informado
            'dataAdquirida' => ['required', 'string'],
            'dataVenda' => ['nullable', 'string'],
            'dataVendida' => ['nullable', 'string'],
            'dataExpiracao' => ['nullable', 'string'],
            'perfilOrigem' => ['required', 'string'],
            'email' => ['nullable', 'string'],
            'qtdTF2' => ['nullable', 'decimal:0,2', 'gt:0'],             // 6.5: quantidade TF2 > 0 se informada
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
