<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representação completa de uma Key para endpoints de CRUD.
 */
class KeyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Identificação
            'id' => $this->id,
            'game_name' => $this->game_name,
            'region' => $this->region,
            'key_code' => $this->key_code,
            'identified_platform' => $this->identified_platform,
            'gamivo_id' => $this->gamivo_id,
            'steam_id' => $this->steam_id,
            'is_duplicate' => $this->is_duplicate,
            'color' => $this->color,
            'notes' => $this->notes,
            'email' => $this->email,

            // Formato, reclamação e plataforma de venda (enums como string)
            'key_format' => $this->key_format,
            'claim_type' => $this->claim_type,
            'sell_platform' => $this->sell_platform,

            // Precificação
            'market_price' => $this->market_price,
            'individual_cost' => $this->individual_cost,
            'total_paid' => $this->total_paid,
            'tf2_quantity' => $this->tf2_quantity,
            'simulated_income' => $this->simulated_income,
            'purchase_profit' => $this->purchase_profit,
            'purchase_profit_percent' => $this->purchase_profit_percent,
            'minimum_sale_price' => $this->minimum_sale_price,
            'min_api' => $this->min_api,
            'max_api' => $this->max_api,
            'sold_price' => $this->sold_price,
            'sale_profit' => $this->sale_profit,
            'sale_profit_percent' => $this->sale_profit_percent,

            // Fornecedor
            'supplier_url' => $this->supplier_url,

            // Datas
            'acquired_at' => $this->acquired_at,
            'listed_at' => $this->listed_at,
            'sold_at' => $this->sold_at,
            'expires_at' => $this->expires_at,

            // Relações
            'supplier' => $this->whenLoaded('supplier'),
        ];
    }
}
