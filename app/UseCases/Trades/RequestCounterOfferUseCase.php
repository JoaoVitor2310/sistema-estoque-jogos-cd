<?php

namespace App\UseCases\Trades;

use App\Domain\Enums\CounterOfferRefusal;
use App\Domain\Enums\TradeDeliveryState;
use App\Domain\Pricing\CurrencyPriceUnavailable;
use App\Domain\Trades\CounterOfferRefused;
use App\Models\Trade;
use App\Services\Trades\TradeOfferService;
use Illuminate\Support\Facades\DB;

/**
 * O supplier pediu mais: o Estoque decide a próxima rodada da oferta.
 *
 * A trade é travada antes de olhar a rodada vigente, para dois pedidos ao
 * mesmo tempo não calcularem a mesma rodada — o índice único
 * (trade_id, round) é a segunda barreira. Qualquer recusa sai antes de gravar.
 */
class RequestCounterOfferUseCase
{
    public function __construct(
        private readonly TradeOfferService $offerService,
    ) {}

    /**
     * @param  int  $requestedCents  total que o supplier pediu, em centavos da moeda da trade
     * @return Trade|null a trade com a nova rodada; `null` se a trade não existe
     *
     * @throws CounterOfferRefused com o motivo da recusa; nada é gravado
     * @throws CurrencyPriceUnavailable quando falta a cotação da TF2 para recalcular
     */
    public function execute(string $uuid, int $requestedCents): ?Trade
    {
        return DB::transaction(function () use ($uuid, $requestedCents) {
            $trade = Trade::where('uuid', $uuid)->lockForUpdate()->first();

            if ($trade === null) {
                return null;
            }

            if ($trade->deliveryState() !== TradeDeliveryState::Negotiating) {
                throw new CounterOfferRefused(CounterOfferRefusal::TradeNotNegotiating);
            }

            $current = $trade->currentOffer()->with('lines')->first();

            if ($current === null) {
                throw new CounterOfferRefused(CounterOfferRefusal::NoOffer);
            }

            if ($current->isFinal()) {
                throw new CounterOfferRefused(CounterOfferRefusal::OfferIsFinal);
            }

            // Pedir o mesmo ou menos não é pedir mais: aceitar é pela entrega.
            if ($requestedCents <= (int) round($current->totalPrice() * 100)) {
                throw new CounterOfferRefused(CounterOfferRefusal::NotAnIncrease);
            }

            $this->offerService->recordCounter($trade, $current, $requestedCents);

            return $trade;
        });
    }
}
