<?php

namespace App\UseCases\Trades;

use App\Domain\Enums\ChatDeliveryRefusal;
use App\Domain\Enums\TradeDeliveryState;
use App\Domain\Enums\TradeLineAuthority;
use App\Domain\Trades\ChatDelivery;
use App\Domain\Trades\ChatDeliveryRefused;
use App\Models\Trade;
use App\Models\TradeLine;
use App\Services\Trades\ChatDeliveryQueries;
use App\UseCases\Trades\DTO\ChatKeysDTO;
use App\UseCases\Trades\DTO\TradeLineDTO;
use Illuminate\Support\Facades\DB;

/**
 * O supplier colou as keys no chat: grava cada uma na linha da trade.
 *
 * É o equivalente do autosave da página da entrega (docs/adr/0008), pelo
 * **mesmo** caminho de escrita: [[UpdateTradeLineUseCase]] com
 * [[TradeLineAuthority::Supplier]]. Colar de novo substitui a key das linhas
 * coladas; as outras ficam como estão. Ver docs/adr/0017.
 *
 * A trade é travada antes de conferir o estado, para um preenchimento não
 * escrever numa trade que uma entrega acabou de fechar. Toda recusa sai antes
 * de gravar, e o pedido é gravado inteiro ou nada.
 */
class FillTradeKeysFromChatUseCase
{
    public function __construct(
        private readonly UpdateTradeLineUseCase $updateTradeLine,
        private readonly ChatDeliveryQueries $queries,
    ) {}

    /**
     * @return Trade|null a trade preenchida; `null` se o supplier não tem trade com esse número
     *
     * @throws ChatDeliveryRefused com o motivo; nada é gravado
     */
    public function execute(string $steamId, int $number, ChatKeysDTO $keys): ?Trade
    {
        return DB::transaction(function () use ($steamId, $number, $keys) {
            $trade = $this->queries->lockedTrade($steamId, $number)->first();

            if ($trade === null) {
                return null;
            }

            if ($trade->deliveryState() !== TradeDeliveryState::Negotiating) {
                throw new ChatDeliveryRefused(ChatDeliveryRefusal::TradeNotNegotiating);
            }

            $deliverable = $this->queries->deliverableLineIds($trade);

            if ($deliverable === []) {
                throw new ChatDeliveryRefused(ChatDeliveryRefusal::NoOffer);
            }

            $outside = ChatDelivery::outsideOffer(array_keys($keys->keyCodes), $deliverable);

            if ($outside !== []) {
                throw new ChatDeliveryRefused(ChatDeliveryRefusal::LineNotInOffer, $outside);
            }

            $lines = $trade->lines()->whereIn('id', array_keys($keys->keyCodes))->get()->keyBy('id');

            foreach ($keys->keyCodes as $lineId => $keyCode) {
                /** @var TradeLine $line */
                $line = $lines->get($lineId);

                $this->updateTradeLine->execute(
                    $line,
                    TradeLineDTO::fromValidated(['key_code' => $keyCode]),
                    TradeLineAuthority::Supplier,
                );
            }

            return $trade;
        });
    }
}
