<?php

namespace App\Http\Resources\Api;

use App\Domain\Enums\TradeDeliveryState;
use App\Models\TradeOffer;
use App\Models\TradeOfferLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Uma trade como a API a mostra: a oferta vigente e o acesso à entrega.
 *
 * Contraparte de [[\App\Services\Trades\TradeApiReadModel]], que faz o
 * `select` — aqui é o formato. Sai o que o supplier pode ler e nada mais:
 * nada de preço de mercado, popularidade, Gamivo ID, lucro, categoria, jogo
 * deixado fora da oferta, `key_code` ou ofertas anteriores.
 *
 * O código da entrega sai aqui, e só aqui (docs/adr/0015): quem manda link e
 * código ao supplier passa a ser o chatbot. Some com o import, como a faixa da
 * aba Trades: a partir dele a credencial não vale mais (docs/adr/0008).
 */
class ApiTradeResource extends JsonResource
{
    /** Sem o envelope `data`: a resposta é a trade. */
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $state = $this->deliveryState();
        $deliveryOpen = $state !== TradeDeliveryState::Imported;

        return [
            'uuid' => $this->uuid,
            'created_at' => $this->created_at?->toIso8601String(),
            'currency' => $this->currency->value,
            'state' => $state->value,
            'offer' => $this->currentOffer === null ? null : self::offer($this->currentOffer),
            'delivery_url' => $deliveryOpen ? route('deliveries.show', ['trade' => $this->uuid]) : null,
            'delivery_code' => $deliveryOpen ? $this->readableDeliveryToken() : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function offer(TradeOffer $offer): array
    {
        return [
            'round' => $offer->round,
            'is_final' => $offer->isFinal(),
            'created_at' => $offer->created_at?->toIso8601String(),
            'total_price' => $offer->totalPrice(),
            'lines' => $offer->lines->map(fn (TradeOfferLine $line) => [
                'game_name' => $line->game_name,
                // Vazio é key global, como no resto da integração.
                'region' => $line->region ?? '',
                'price' => $line->price,
            ])->all(),
        ];
    }
}
