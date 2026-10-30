<?php

namespace App\Services\Trades;

use App\Domain\Enums\PurchaseChannel;
use App\Domain\Enums\SupplierCategory;
use App\Domain\Enums\TradeCurrency;
use App\Domain\Pricing\CurrencyPriceUnavailable;
use App\Domain\Pricing\IncomeCalculator;
use App\Domain\Pricing\SupplierOffer;
use App\Domain\Trades\TradeLineFlags;
use App\Models\Trade;
use App\Models\TradeLine;
use App\Models\TradeOffer;
use App\Services\Keys\KeyCalculationService;
use Illuminate\Support\Facades\DB;

/**
 * Grava as ofertas feitas a um supplier numa trade (docs/adr/0016).
 *
 * Quanto ofertar é do Domain — o lucro pela categoria
 * ([[SupplierCategory::initialProfitPercent]]), o valor por jogo limitado à
 * oferta máxima ([[SupplierOffer]]) e o que fica de fora da oferta
 * ([[TradeLineFlags]]). Aqui se reúnem as taxas e as cotações vigentes e se
 * grava o registro.
 */
class TradeOfferService
{
    public function __construct(
        private readonly KeyCalculationService $calculationService,
    ) {}

    /**
     * Grava a primeira oferta da trade, na moeda dela.
     *
     * `null`, sem gravar nada, quando não há o que ofertar: o supplier está
     * bloqueado, ou nenhum jogo é ofertável (encalhado, em giveaway, sem preço,
     * que não paga as taxas, ou cuja oferta máxima não comporta o piso da moeda).
     *
     * @throws CurrencyPriceUnavailable quando não há cotação da TF2 para calcular ou converter a oferta
     * @throws \LogicException quando a trade não tem supplier ou já tem oferta
     */
    public function recordInitial(Trade $trade): ?TradeOffer
    {
        $supplier = $trade->supplier;

        if ($supplier === null) {
            throw new \LogicException("Trade {$trade->id} has no supplier to make an offer to.");
        }

        // A primeira oferta é uma só. O índice único (trade_id, round) também
        // barra a segunda, mas a recusa aqui diz o motivo.
        if ($trade->offers()->exists()) {
            throw new \LogicException("Trade {$trade->id} already has an offer; the initial one cannot be recorded again.");
        }

        $profitPercent = SupplierCategory::initialProfitPercent($supplier->category);

        if ($profitPercent === null) {
            return null;
        }

        $currency = $trade->currency;
        $tf2EuroPrice = $this->calculationService->getTf2EuroPrice();
        $tf2PriceInCurrency = $this->calculationService->getTf2Price($currency);

        // A oferta nasce em TF2 a partir do euro, e só depois é convertida:
        // sem o preço em euro não há oferta em moeda nenhuma — e é o euro que
        // falta, seja qual for a moeda da trade.
        if ($tf2EuroPrice <= 0) {
            throw CurrencyPriceUnavailable::for(TradeCurrency::Eur);
        }

        if (! $currency->hasTf2Price($tf2PriceInCurrency)) {
            throw CurrencyPriceUnavailable::for($currency);
        }

        $fee = $this->calculationService->getMarketplaceFee();

        $offered = [];

        foreach ($trade->lines as $line) {
            if (! $this->isOfferable($line)) {
                continue;
            }

            $netIncome = IncomeCalculator::forGamivo((float) $line->market_price, $fee);
            $offer = SupplierOffer::line($netIncome, $profitPercent, $tf2EuroPrice, PurchaseChannel::SupplierTrade);

            if ($offer !== null && $offer->fitsMinimumPriceIn($currency, $tf2PriceInCurrency)) {
                $offered[] = ['line' => $line, 'offer' => $offer];
            }
        }

        if ($offered === []) {
            return null;
        }

        $rows = array_map(fn (array $item) => [
            'trade_line_id' => $item['line']->id,
            'game_name' => $item['line']->game_name,
            'region' => $item['line']->region,
            'price' => $item['offer']->priceIn($currency, $tf2PriceInCurrency),
        ], $offered);

        return DB::transaction(function () use ($trade, $profitPercent, $currency, $tf2PriceInCurrency, $rows) {
            $offer = TradeOffer::create([
                'trade_id' => $trade->id,
                'round' => SupplierOffer::FIRST_ROUND,
                'profit_percent' => $profitPercent,
                'currency' => $currency,
                'tf2_rate' => $tf2PriceInCurrency,
            ]);

            $offer->lines()->createMany($rows);

            return $offer;
        });
    }

    /** Linha com jogo e preço, que não esteja encalhada nem em giveaway. */
    private function isOfferable(TradeLine $line): bool
    {
        return filled($line->game_name)
            && (float) $line->market_price > 0
            && TradeLineFlags::isOfferable($line->only(TradeLineFlags::COLUMNS));
    }
}
