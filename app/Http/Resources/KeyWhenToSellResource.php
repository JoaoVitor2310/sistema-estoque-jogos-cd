<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Campos necessários para o fluxo "quando listar" (whenToSell).
 * Contrato explícito: só expõe o que o caller de fato precisa.
 */
class KeyWhenToSellResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'idGamivo'            => $this->idGamivo,
            'minimoParaVenda'     => $this->minimoParaVenda,
            'valorPagoIndividual' => $this->valorPagoIndividual,
            'chaveRecebida'       => $this->chaveRecebida,
            'nomeJogo'            => $this->nomeJogo,
            'region'              => $this->region,
            'dataAdquirida'       => $this->dataAdquirida,
            'dataVenda'           => $this->dataVenda,
            'dataVendida'         => $this->dataVendida,
            'dataExpiracao'       => $this->dataExpiracao,
        ];
    }
}
