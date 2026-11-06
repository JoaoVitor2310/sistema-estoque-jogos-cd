<?php

/*
|--------------------------------------------------------------------------
| Contrato entre o Domain PHP e a projeção da aba Trades
|--------------------------------------------------------------------------
|
| `resources/js/domain/tradeOffer.ts` recalcula na tela regras cuja fonte da
| verdade é este Domain. Os dois lados leem os mesmos casos de
| tests/Fixtures/trade-offer-cases.json: mudar uma regra de um lado só quebra a
| suíte do outro. Mudou aqui, muda o fixture e a projeção.
|
*/

use App\Domain\Enums\PurchaseChannel;
use App\Domain\Enums\TradeCurrency;
use App\Domain\Pricing\IncomeCalculator;
use App\Domain\Pricing\MinimumMarginPolicy;
use App\Domain\Pricing\OfferCalculator;
use App\Domain\Pricing\ValueObjects\MarketplaceFee;
use App\Domain\Pricing\ValueObjects\SupplierOfferLine;
use Carbon\Carbon;

function tradeOfferCases(): array
{
    return json_decode(file_get_contents(__DIR__.'/../../../Fixtures/trade-offer-cases.json'), true, flags: JSON_THROW_ON_ERROR);
}

describe('Trade offer projection contract', function () {

    it('agrees on the net income', function (float $marketPrice, float $expected) {
        $fees = tradeOfferCases()['fees'];
        $fee = new MarketplaceFee($fees['percentLow'], $fees['fixedLow'], $fees['percentHigh'], $fees['fixedHigh']);

        expect(IncomeCalculator::forGamivo($marketPrice, $fee))->toEqualWithDelta($expected, 0.000001);
    })->with(fn () => array_map(fn (array $case) => [$case['marketPrice'], $case['expected']], tradeOfferCases()['netIncome']));

    it('agrees on the TF2 offer', function (float $income, float $profitPercent, float $tf2EuroPrice, float $expected) {
        expect(OfferCalculator::tf2Offer($income, $profitPercent, $tf2EuroPrice))->toEqualWithDelta($expected, 0.000001);
    })->with(fn () => array_map(
        fn (array $case) => [$case['income'], $case['profitPercent'], $case['tf2EuroPrice'], $case['expected']],
        tradeOfferCases()['tf2Offer'],
    ));

    it('agrees on the required margin', function (float $cost, string $channel, float $expected) {
        $now = Carbon::now();

        // Comprada agora: só a margem inicial, que é a que a tela projeta
        $margin = MinimumMarginPolicy::requiredMargin($cost, $now, PurchaseChannel::from($channel), now: $now);

        expect($margin * 100)->toEqualWithDelta($expected, 0.000001);
    })->with(fn () => array_map(fn (array $case) => [$case['cost'], $case['channel'], $case['expected']], tradeOfferCases()['requiredMarginPercent']));

    it('agrees on the price the supplier reads', function (float $tf2Offer, string $currency, float $tf2Price, float $expected) {
        $line = new SupplierOfferLine($tf2Offer, $tf2Offer);

        expect($line->priceIn(TradeCurrency::from($currency), $tf2Price))->toEqualWithDelta($expected, 0.000001);
    })->with(fn () => array_map(
        fn (array $case) => [$case['tf2Offer'], $case['currency'], $case['tf2Price'], $case['expected']],
        tradeOfferCases()['offerPrice'],
    ));

    it('uses the margin rules the backend sends to the screen', function () {
        expect(MinimumMarginPolicy::initialMarginRules())->toEqual(tradeOfferCases()['marginRules']);
    });
});
