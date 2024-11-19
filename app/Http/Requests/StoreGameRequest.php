<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;


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
            "color" => "nullable", // Cor da linha na tabela
            "tipo_reclamacao_id" => "integer|min:1|max:4",
            "steamId" => ["string", "nullable"],
            "idGamivo" => ["string", "nullable"],
            "tipo_formato_id" => "integer|min:1|max:7",
            "chaveRecebida" => "required",
            "nomeJogo" => "required",
            "precoJogo" => ["required", "decimal:0,2"],
            "notaMetacritic" => "integer|min:0|max:100",
            "isSteam" => ["boolean", "nullable"],
            "observacao" => ["string", "nullable"],
            "id_leilao_g2a" => "integer|min:1|max:4",
            "id_leilao_gamivo" => "integer|min:1|max:4",
            "id_leilao_kinguin" => "integer|min:1|max:4",
            "id_plataforma" => "integer|min:1|max:5",
            "precoCliente" => ["required", "decimal:0,2"],
            "minimoParaVenda" => ["nullable", "decimal:0,2"],
            "chaveEntregue" => ["string", "nullable"],
            "valorPagoTotal" => ["string", "nullable"],
            "vendido" => "boolean",
            "leiloes" => "integer|min:0",
            "quantidade" => "integer|min:0",
            "devolucoes" => "boolean",
            "valorVendido" => ["nullable", "decimal:0,2"],
            "dataAdquirida" => ["required", "date"],
            "dataVenda" => ["nullable", "date"],
            "dataVendida" => ["nullable", "date"],
            "perfilOrigem" => ["required", "string"],
            "email" => ["nullable", "string"],
            "qtdTF2" => ["nullable", "decimal:0,2"],
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'statusCode' => 422,
            'message' => 'Dados inválidos',
            'errors' => $validator->errors(),
            'data' => []
        ], 422));
    }
}
