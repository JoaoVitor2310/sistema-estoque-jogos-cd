<?php

namespace App\Services\Trades;

use App\Domain\Trades\ChatDelivery;
use App\Models\Trade;
use App\Models\TradeOfferLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * As consultas da entrega pelo chat (docs/adr/0017), num lugar só: o
 * preenchimento, a entrega e a resposta do preenchimento precisam concordar
 * sobre qual trade é travada e quais linhas contam.
 */
final class ChatDeliveryQueries
{
    /**
     * A trade de um supplier pelo número, travada para a escrita.
     *
     * `FOR UPDATE`: o preenchimento e a entrega leem o estado e as keys antes de
     * gravar, e sem a trava uma key colada atrasada entraria numa trade que a
     * confirmação acabou de fechar. Nenhum dos dois insere linha com chave
     * estrangeira para a trade antes de travar, então não há o deadlock do
     * `FOR KEY SHARE` (ver docs/agents/testing.md). O SQLite dos testes ignora o
     * modo; a guarda compila esta consulta para Postgres.
     *
     * @return Builder<Trade>
     */
    public function lockedTrade(string $steamId, int $number): Builder
    {
        return Trade::ofSupplierNumber($steamId, $number)->lockForUpdate();
    }

    /**
     * As linhas da oferta vigente que o supplier entrega pelo chat, na ordem da
     * oferta: as que ainda têm linha na trade.
     *
     * @return Collection<int, TradeOfferLine>
     */
    public function deliverableOfferLines(Trade $trade): Collection
    {
        $offer = $trade->currentOffer()->first();

        if ($offer === null) {
            return collect();
        }

        return $offer->lines()->get()
            ->filter(fn (TradeOfferLine $line) => ChatDelivery::isDeliverable($line->trade_line_id))
            ->values()
            ->toBase();
    }

    /**
     * As linhas da trade, entre as entregáveis, ainda sem key. Lê o `key_code`
     * só para responder se ele existe: a key nunca sai pela API.
     *
     * @return list<int> ids das linhas da trade, na ordem da oferta
     */
    public function linesWithoutKey(Trade $trade): array
    {
        $deliverable = $this->deliverableLineIds($trade);

        return ChatDelivery::missingKeys(
            $deliverable,
            $trade->lines()->whereIn('id', $deliverable)->pluck('key_code', 'id')->all(),
        );
    }

    /** @return list<int> */
    public function deliverableLineIds(Trade $trade): array
    {
        return $this->deliverableOfferLines($trade)
            ->map(fn (TradeOfferLine $line) => (int) $line->trade_line_id)
            ->values()
            ->all();
    }
}
