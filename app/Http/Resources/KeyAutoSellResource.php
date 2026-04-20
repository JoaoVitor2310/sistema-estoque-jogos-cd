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
            'nomeJogo'            => $this->nomeJogo,
            'game_region'         => $this->region,
            'bundle_type'         => $newestBundle?->type,
            'bundle_launch_price' => $newestBundle?->pivot->bundle_launch_price,
            'game_popularity'     => $this->game?->popularity,
            'bundle_release_date' => $newestBundle?->release_date,
            'idGamivo'            => $this->idGamivo,
            'precoCliente'        => $this->precoCliente,
            'lucroPercentual'     => $this->lucroPercentual,
            'minimoParaVenda'     => $this->minimoParaVenda,
            'valorPagoIndividual' => $this->valorPagoIndividual,
            'chaveRecebida'       => $this->chaveRecebida,
            'dataAdquirida'       => $this->dataAdquirida,
            'dataVenda'           => $this->dataVenda,
            'dataVendida'         => $this->dataVendida,
            'dataExpiracao'       => $this->dataExpiracao,
            'game_release_date'   => $this->game?->release_date,
        ];
    }
}
