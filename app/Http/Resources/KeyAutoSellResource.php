<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforma uma Venda_chave_troca com game.bundles eager-loaded
 * no formato achatado esperado pelo client para o autoSell.
 *
 * Bundles devem vir ordenados por release_date desc (ver KeyRepository),
 * de forma que ->first() retorne sempre o bundle mais recente.
 */
class KeyAutoSellResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $newestBundle = $this->game?->bundles->first();

        return [
            'nomeJogo' => $this->game_name,
            'game_region' => $this->region,
            'bundle_type' => $newestBundle?->type,
            'bundle_launch_price' => $newestBundle?->pivot->bundle_launch_price,
            'game_popularity' => $this->game?->popularity,
            'bundle_release_date' => $newestBundle?->release_date,
            'idGamivo' => $this->gamivo_id,
            'precoCliente' => $this->market_price,
            'lucroPercentual' => $this->purchase_profit_percent,
            'minimoParaVenda' => $this->minimum_sale_price,
            'valorPagoIndividual' => $this->individual_cost,
            'chaveRecebida' => $this->key_code,
            'dataAdquirida' => $this->acquired_at,
            'dataVenda' => $this->listed_at,
            'dataVendida' => $this->sold_at,
            'dataExpiracao' => $this->expires_at,
            'game_release_date' => $this->game?->release_date,
        ];
    }
}
