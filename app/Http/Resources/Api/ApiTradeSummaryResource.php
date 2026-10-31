<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Uma trade na lista das abertas de um supplier: o bastante para ele escolher
 * qual ver.
 *
 * **Sem o acesso à entrega:** quem quiser link e código lê a trade pelo uuid
 * ([[ApiTradeResource]]). A lista não vira atalho para colher os códigos de
 * todas as trades de uma vez (docs/adr/0015).
 */
class ApiTradeSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $offer = $this->currentOffer;

        return [
            'uuid' => $this->uuid,
            'created_at' => $this->created_at?->toIso8601String(),
            'currency' => $this->currency->value,
            'games_count' => $offer?->lines->count() ?? 0,
            'total_price' => $offer?->totalPrice(),
        ];
    }
}
