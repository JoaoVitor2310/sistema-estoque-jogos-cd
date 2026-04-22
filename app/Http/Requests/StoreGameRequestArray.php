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
            'games.*.tipo_reclamacao_id' => ['integer', 'exists:tipo_reclamacao,id'],   // 6.6: FK dinâmica
            'games.*.steamId' => ['string', 'nullable'],
            'games.*.idGamivo' => ['string', 'nullable'],
            'games.*.tipo_formato_id' => ['integer', 'exists:tipo_formato,id'],      // 6.6: FK dinâmica
            'games.*.chaveRecebida' => 'required',
            'games.*.nomeJogo' => 'required',
            'games.*.region' => ['string', 'nullable'],
            'games.*.precoJogo' => ['nullable', 'decimal:0,2'],
            'games.*.notaMetacritic' => 'integer|min:0|max:100',
            'games.*.isSteam' => ['boolean', 'nullable'],
            'games.*.observacao' => ['string', 'nullable'],
            'games.*.id_leilao_g2a' => ['integer', 'exists:tipo_leilao,id'],       // 6.6: FK dinâmica
            'games.*.id_leilao_gamivo' => ['integer', 'exists:tipo_leilao,id'],       // 6.6: FK dinâmica
            'games.*.id_leilao_kinguin' => ['integer', 'exists:tipo_leilao,id'],       // 6.6: FK dinâmica
            'games.*.id_plataforma' => ['integer', 'exists:plataforma,id'],        // 6.6: FK dinâmica
            'games.*.precoCliente' => ['required', 'decimal:0,2', 'gt:0'],        // 6.5: preço > 0
            'games.*.minimoParaVenda' => ['nullable', 'decimal:0,2'],
            'games.*.minApiGamivo' => ['nullable', 'decimal:0,2'],
            'games.*.maxApiGamivo' => ['nullable', 'decimal:0,2'],
            'games.*.chaveEntregue' => ['string', 'nullable'],
            'games.*.valorPagoTotal' => ['string', 'nullable'],
            'games.*.vendido' => 'boolean',
            'games.*.leiloes' => 'integer|min:0',
            'games.*.quantidade' => 'integer|min:0',
            'games.*.devolucoes' => 'boolean',
            'games.*.valorVendido' => ['nullable', 'decimal:0,2', 'gt:0'],        // 6.5: valor vendido > 0 se informado
            'games.*.dataAdquirida' => ['required', 'string'],
            'games.*.dataVenda' => ['nullable', 'string'],
            'games.*.dataVendida' => ['nullable', 'string'],
            'games.*.dataExpiracao' => ['nullable', 'string'],
            'games.*.perfilOrigem' => ['required', 'string'],
            'games.*.email' => ['nullable', 'string'],
            'games.*.qtdTF2' => ['required', 'decimal:0,2', 'gt:0'],        // 6.5: quantidade TF2 > 0
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
