<?php

use App\Domain\Enums\TradeCurrency;

describe('TradeCurrency', function () {

    it('treats only euro and dollar as cash', function () {
        expect(TradeCurrency::Tf2->isCash())->toBeFalse()
            ->and(TradeCurrency::Eur->isCash())->toBeTrue()
            ->and(TradeCurrency::Usd->isCash())->toBeTrue();
    });

    it('is always quoted in TF2, and quoted in cash only with a positive TF2 price', function () {
        expect(TradeCurrency::Tf2->hasTf2Price(0.0))->toBeTrue()
            ->and(TradeCurrency::Eur->hasTf2Price(0.95))->toBeTrue()
            ->and(TradeCurrency::Eur->hasTf2Price(0.0))->toBeFalse()
            ->and(TradeCurrency::Usd->hasTf2Price(0.0))->toBeFalse();
    });

    it('falls back to TF2 when there is no TF2 price in the currency', function () {
        expect(TradeCurrency::Eur->orTf2WithoutPrice(0.95))->toBe(TradeCurrency::Eur)
            ->and(TradeCurrency::Eur->orTf2WithoutPrice(0.0))->toBe(TradeCurrency::Tf2)
            ->and(TradeCurrency::Usd->orTf2WithoutPrice(0.0))->toBe(TradeCurrency::Tf2)
            ->and(TradeCurrency::Tf2->orTf2WithoutPrice(0.0))->toBe(TradeCurrency::Tf2);
    });
});
