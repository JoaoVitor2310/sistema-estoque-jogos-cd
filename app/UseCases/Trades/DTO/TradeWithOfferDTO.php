<?php

namespace App\UseCases\Trades\DTO;

use App\Domain\Enums\TradeCurrency;

/**
 * Os jogos precificados que viram uma trade já com a primeira oferta ao
 * supplier, e a moeda que ele escolheu.
 */
final class TradeWithOfferDTO
{
    /**
     * @param  list<array{name: string, market_price_euro: float, popularity: int, region: string|null, gamivo_id: string|null}>  $games
     */
    public function __construct(
        public readonly string $supplierSteamId,
        public readonly array $games,
        public readonly TradeCurrency $currency,
        public readonly ?string $title,
        public readonly ?string $listCode,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  saída de `StoreTradeWithOfferRequest::validated()`
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            supplierSteamId: $validated['supplier_steam_id'],
            games: array_values(array_map(fn (array $game) => [
                'name' => $game['name'],
                'market_price_euro' => (float) $game['market_price_euro'],
                'popularity' => (int) $game['popularity'],
                'region' => $game['region'] ?? null,
                'gamivo_id' => $game['gamivo_id'] ?? null,
            ], $validated['games'])),
            // Sem moeda, TF2: é o que o price_researcher de hoje entende.
            currency: TradeCurrency::from($validated['currency'] ?? TradeCurrency::Tf2->value),
            title: $validated['title'] ?? null,
            listCode: $validated['list_code'] ?? null,
        );
    }
}
