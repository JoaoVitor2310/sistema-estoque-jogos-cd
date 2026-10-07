<?php

use App\Domain\Pricing\OfferCalculator;

describe('OfferCalculator::inCurrency', function () {

    it('converts a TF2 offer through the TF2 price in the currency, rounded to 2 places', function () {
        // 0,50 TF2 × €2,40 = €1,20 ; 0,50 TF2 × $2,65 = $1,325 → 1,33
        expect(OfferCalculator::inCurrency(0.5, 2.40))->toBe(1.20)
            ->and(OfferCalculator::inCurrency(0.5, 2.65))->toBe(1.33);
    });

    it('never publishes an offer below the minimum cash offer', function () {
        // 0,004 TF2 × €0,95 = 0,0038 → arredondaria para 0,00
        expect(OfferCalculator::inCurrency(0.004, 0.95))->toBe(OfferCalculator::MIN_CASH_OFFER);
    });
});

describe('OfferCalculator::toTf2Quantity', function () {

    it('converts a cash amount into TF2 keys at the current price', function () {
        expect(OfferCalculator::toTf2Quantity(3.0, 2.0))->toBe(1.5);
    });

    it('rounds to the 2 places of trades.amount, so the key looks like any TF2 trade', function () {
        // 1 USD / $2,20 = 0,4545… → 0,45
        expect(OfferCalculator::toTf2Quantity(1.0, 2.2))->toBe(0.45);
    });

    it('returns zero when the TF2 has no price in the currency', function () {
        expect(OfferCalculator::toTf2Quantity(3.0, 0.0))->toBe(0.0);
    });
});
