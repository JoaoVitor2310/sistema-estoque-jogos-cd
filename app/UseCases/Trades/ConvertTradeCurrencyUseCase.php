<?php

namespace App\UseCases\Trades;

use App\Domain\Enums\TradeCurrency;
use App\Domain\Pricing\CurrencyPriceUnavailable;
use App\Domain\Pricing\OfferCalculator;
use App\Domain\Trades\TradeCurrencyLocked;
use App\Models\Trade;
use App\Services\Keys\KeyCalculationService;
use Illuminate\Support\Facades\DB;

class ConvertTradeCurrencyUseCase
{
    public function __construct(
        private readonly KeyCalculationService $calculationService,
    ) {}

    /**
     * Troca a moeda da trade, convertendo a quantidade acertada para ela.
     *
     * A trava de linha faz de duas trocas simultâneas uma fila: a segunda lê a
     * quantidade já convertida, e não converte de novo a partir da antiga.
     *
     * @return Trade a trade como ficou gravada
     *
     * @throws TradeCurrencyLocked quando a trade já tem oferta; nada é gravado
     * @throws CurrencyPriceUnavailable quando falta a cotação da TF2 numa das duas moedas; nada é gravado
     */
    public function execute(Trade $trade, TradeCurrency $target): Trade
    {
        return DB::transaction(function () use ($trade, $target) {
            $locked = Trade::whereKey($trade->id)->lockForUpdate()->firstOrFail();
            $current = $locked->currency;

            if ($current === $target) {
                return $locked;
            }

            if ($locked->offers()->exists()) {
                throw new TradeCurrencyLocked($locked->id);
            }

            $currentPrice = $this->tf2PriceIn($current);
            $targetPrice = $this->tf2PriceIn($target);

            $locked->update([
                'currency' => $target,
                'amount' => OfferCalculator::convertAmount(
                    $locked->amount === null ? null : (float) $locked->amount,
                    $currentPrice,
                    $targetPrice,
                ),
            ]);

            return $locked;
        });
    }

    private function tf2PriceIn(TradeCurrency $currency): float
    {
        $price = $this->calculationService->getTf2Price($currency);

        if (! $currency->hasTf2Price($price)) {
            throw CurrencyPriceUnavailable::for($currency);
        }

        return $price;
    }
}
