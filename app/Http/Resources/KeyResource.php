<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representação completa de uma Venda_chave_troca para endpoints de CRUD.
 */
class KeyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Identificação
            'id' => $this->id,
            'nomeJogo' => $this->nomeJogo,
            'region' => $this->region,
            'chaveRecebida' => $this->chaveRecebida,
            'plataformaIdentificada' => $this->plataformaIdentificada,
            'idGamivo' => $this->idGamivo,
            'steamId' => $this->steamId,
            'repetido' => $this->repetido,
            'color' => $this->color,
            'observacao' => $this->observacao,
            'email' => $this->email,

            // Formato, reclamação e plataforma de venda (enums como string)
            'key_format' => $this->key_format,
            'claim_type' => $this->claim_type,
            'sell_platform' => $this->sell_platform,

            // Precificação
            'precoCliente' => $this->precoCliente,
            'valorPagoIndividual' => $this->valorPagoIndividual,
            'valorPagoTotal' => $this->valorPagoTotal,
            'qtdTF2' => $this->qtdTF2,
            'incomeSimulado' => $this->incomeSimulado,
            'lucroRS' => $this->lucroRS,
            'lucroPercentual' => $this->lucroPercentual,
            'minimoParaVenda' => $this->minimoParaVenda,
            'minApiGamivo' => $this->minApiGamivo,
            'maxApiGamivo' => $this->maxApiGamivo,
            'valorVendido' => $this->valorVendido,
            'lucroVendaRS' => $this->lucroVendaRS,
            'lucroVendaPercentual' => $this->lucroVendaPercentual,

            // Fornecedor
            'perfilOrigem' => $this->perfilOrigem,

            // Datas
            'dataAdquirida' => $this->dataAdquirida,
            'dataVenda' => $this->dataVenda,
            'dataVendida' => $this->dataVendida,
            'dataExpiracao' => $this->dataExpiracao,

            // Relações
            'fornecedor' => $this->whenLoaded('fornecedor'),
        ];
    }
}
