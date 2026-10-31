<?php

use App\Domain\Enums\TradeCurrency;
use App\Domain\Pricing\OfferCalculator;
use App\Domain\Pricing\ValueObjects\SupplierOfferLine;

describe('SupplierOfferLine', function () {

    it('reads in TF2 rounded to two places, never below the minimum TF2 offer', function () {
        expect((new SupplierOfferLine(1.23456, 2.0))->priceIn(TradeCurrency::Tf2, TradeCurrency::TF2_UNIT_PRICE))->toBe(1.23)
            ->and((new SupplierOfferLine(0.004, 2.0))->priceIn(TradeCurrency::Tf2, TradeCurrency::TF2_UNIT_PRICE))->toBe(OfferCalculator::MIN_TF2_OFFER);
    });

    it('fits the minimum price only when the maximum offer can pay it', function () {
        // Oferta máxima de 0,008 TF2: pagar o piso de 0,01 passaria do min_api
        expect((new SupplierOfferLine(0.005, 0.008))->fitsMinimumPriceIn(TradeCurrency::Tf2, TradeCurrency::TF2_UNIT_PRICE))->toBeFalse()
            ->and((new SupplierOfferLine(0.005, 0.02))->fitsMinimumPriceIn(TradeCurrency::Tf2, TradeCurrency::TF2_UNIT_PRICE))->toBeTrue()
            // Em euro, o piso é 0,01 €: 0,008 TF2 × 1,85 = 0,0148 € cabe
            ->and((new SupplierOfferLine(0.005, 0.008))->fitsMinimumPriceIn(TradeCurrency::Eur, 1.85))->toBeTrue()
            ->and((new SupplierOfferLine(0.002, 0.005))->fitsMinimumPriceIn(TradeCurrency::Eur, 1.85))->toBeFalse();
    });

    it('reads in cash through the exact offer, never below the minimum cash offer', function () {
        // 1,2349 TF2 × 10 €/TF2 = 12,349 → 12,35; converter o 1,23 já arredondado daria 12,30
        expect((new SupplierOfferLine(1.2349, 2.0))->priceIn(TradeCurrency::Eur, 10.0))->toBe(12.35)
            ->and((new SupplierOfferLine(0.001, 2.0))->priceIn(TradeCurrency::Eur, 1.85))->toBe(OfferCalculator::MIN_CASH_OFFER);
    });

    it('refuses an offer that is not positive or that exceeds the maximum', function () {
        expect(fn () => new SupplierOfferLine(0.0, 2.0))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new SupplierOfferLine(2.5, 2.0))->toThrow(InvalidArgumentException::class);
    });

    it('converts the exact offer into the currency without rounding', function () {
        expect((new SupplierOfferLine(1.2349, 2.0))->exactPriceIn(TradeCurrency::Eur, 10.0))->toEqualWithDelta(12.349, 1e-9)
            ->and((new SupplierOfferLine(1.2349, 2.0))->exactPriceIn(TradeCurrency::Tf2, TradeCurrency::TF2_UNIT_PRICE))->toBe(1.2349);
    });

    it('rounds the price the supplier reads to the nearest cent, but the counter-offer limit down', function () {
        // 2,615 TF2: lido como 2,62; como limite, 261 centavos — nunca acima da oferta exata
        $line = new SupplierOfferLine(2.615, 3.0);

        expect($line->priceIn(TradeCurrency::Tf2, TradeCurrency::TF2_UNIT_PRICE))->toBe(2.62)
            ->and($line->limitCentsIn(TradeCurrency::Tf2, TradeCurrency::TF2_UNIT_PRICE))->toBe(261)
            // 1,2349 × 10 € = 12,349 € → limite de 1234 centavos
            ->and((new SupplierOfferLine(1.2349, 2.0))->limitCentsIn(TradeCurrency::Eur, 10.0))->toBe(1234);
    });

    it('does not lose a cent to float error when the exact value is a whole cent', function () {
        // 2,61 em float é 2,6099999…; o limite tem de ser 261, não 260
        expect((new SupplierOfferLine(2.61, 3.0))->limitCentsIn(TradeCurrency::Tf2, TradeCurrency::TF2_UNIT_PRICE))->toBe(261);
    });
});
