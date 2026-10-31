<?php

namespace App\Services\Trades;

use App\Models\Trade;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * As consultas das trades lidas pela API (integração com o chatbot).
 *
 * Classe própria pelo mesmo motivo de [[DeliveryReadModel]]: quem lê é o lado
 * que fala com o supplier, e preço de mercado, popularidade, Gamivo ID, margem
 * ou jogo deixado fora da oferta não podem sair por aqui. A oferta vigente vem
 * com as colunas da linha escolhidas à mão — uma coluna nova em
 * `trade_offer_lines` não entra sem alguém digitar o nome dela. O formato é de
 * [[\App\Http\Resources\Api\ApiTradeResource]].
 */
final class TradeApiReadModel
{
    /** As colunas da linha da oferta que saem; `id` e `trade_offer_id` só ligam a relação. */
    public const OFFER_LINE_COLUMNS = ['id', 'trade_offer_id', 'game_name', 'region', 'price'];

    /** A trade pelo uuid, com a oferta vigente; `null` se não existe ou foi apagada. */
    public function findByUuid(string $uuid): ?Trade
    {
        return $this->withCurrentOffer(Trade::query())->where('uuid', $uuid)->first();
    }

    /**
     * As trades em negociação de um supplier, da mais recente para a mais
     * antiga, cada uma com a oferta vigente.
     *
     * @return Collection<int, Trade>
     */
    public function negotiatingForSupplier(string $steamId): Collection
    {
        return $this->withCurrentOffer(Trade::query())
            ->negotiating()
            ->whereHas('supplier', fn (Builder $query) => $query->where('steam_id', $steamId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  Builder<Trade>  $query
     * @return Builder<Trade>
     */
    private function withCurrentOffer(Builder $query): Builder
    {
        return $query->with(['currentOffer.lines' => fn ($lines) => $lines->select(self::OFFER_LINE_COLUMNS)]);
    }
}
