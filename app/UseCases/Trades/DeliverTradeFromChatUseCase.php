<?php

namespace App\UseCases\Trades;

use App\Domain\Enums\ChatDeliveryRefusal;
use App\Domain\Enums\TradeDeliveryState;
use App\Domain\Trades\ChatDelivery;
use App\Domain\Trades\ChatDeliveryRefused;
use App\Models\Trade;
use App\Models\TradeOfferLine;
use App\Services\Trades\ChatDeliveryQueries;
use Illuminate\Support\Facades\DB;

/**
 * O supplier confirmou no chat: a entrega fecha, como no botão da página.
 *
 * Duas diferenças da página (docs/adr/0017): a entrega é **só completa** —
 * toda linha da oferta precisa de key —, e o total acertado não vem do
 * supplier: é o da oferta vigente, gravado aqui. No chat, mandar as keys é
 * aceitar a oferta.
 *
 * Fecha pelo mesmo [[MarkTradeDeliveredUseCase]] da página: o mesmo marco, a
 * mesma régua do total e o mesmo aviso à equipe.
 */
class DeliverTradeFromChatUseCase
{
    public function __construct(
        private readonly MarkTradeDeliveredUseCase $markDelivered,
        private readonly ChatDeliveryQueries $queries,
    ) {}

    /**
     * @return Trade|null a trade entregue; `null` se o supplier não tem trade com esse número
     *
     * @throws ChatDeliveryRefused com o motivo; nada é gravado
     */
    public function execute(string $steamId, int $number): ?Trade
    {
        return DB::transaction(function () use ($steamId, $number) {
            $trade = $this->queries->lockedTrade($steamId, $number)->first();

            if ($trade === null) {
                return null;
            }

            // Entregue e ainda não importada: a confirmação repetida (o supplier
            // confirmou duas vezes, ou o chatbot reenviou) não é erro para ele.
            // O motivo próprio deixa o chatbot tratá-la como sucesso.
            if ($trade->deliveryState() === TradeDeliveryState::AwaitingReview) {
                throw new ChatDeliveryRefused(ChatDeliveryRefusal::AlreadyDelivered);
            }

            if ($trade->deliveryState() !== TradeDeliveryState::Negotiating) {
                throw new ChatDeliveryRefused(ChatDeliveryRefusal::TradeNotNegotiating);
            }

            $offerLines = $this->queries->deliverableOfferLines($trade);

            if ($offerLines->isEmpty()) {
                throw new ChatDeliveryRefused(ChatDeliveryRefusal::NoOffer);
            }

            $missing = $this->queries->linesWithoutKey($trade);

            if ($missing !== []) {
                throw new ChatDeliveryRefused(ChatDeliveryRefusal::MissingKeys, $missing);
            }

            $cents = ChatDelivery::agreedTotalCents(
                $offerLines->map(fn (TradeOfferLine $line) => (float) $line->price)->all(),
            );

            $trade->amount = number_format($cents / 100, 2, '.', '');

            // A oferta não tem jogo de valor zero (cada um vale ao menos 0,01),
            // então a régua do total sempre aceita aqui.
            if (! $this->markDelivered->execute($trade)) {
                throw new \LogicException("The offer total of trade {$trade->id} was not accepted as the agreed amount.");
            }

            return $trade;
        });
    }
}
