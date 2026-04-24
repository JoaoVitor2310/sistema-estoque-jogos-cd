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
            'idGamivo' => $this->gamivo_id,
            'minimoParaVenda' => $this->minimum_sale_price,
            'valorPagoIndividual' => $this->individual_cost,
            'chaveRecebida' => $this->key_code,
            'nomeJogo' => $this->game_name,
            'region' => $this->region,
            'dataAdquirida' => $this->acquired_at,
            'dataVenda' => $this->listed_at,
            'dataVendida' => $this->sold_at,
            'dataExpiracao' => $this->expires_at,
        ];
    }
}
