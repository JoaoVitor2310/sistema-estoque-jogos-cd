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
});
