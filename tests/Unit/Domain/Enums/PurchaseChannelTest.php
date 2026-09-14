<?php

/*
|--------------------------------------------------------------------------
| PurchaseChannelTest — qual contraparte cada canal de compra exige
|--------------------------------------------------------------------------
|
| É daqui que a ImportReadinessPolicy tira o que cobrar e o UpdateTradeUseCase
| tira o que descartar — um canal novo precisa decidir as duas respostas. E o
| texto de origem gravado na key, que nunca fica vazio.
|
*/

use App\Domain\Enums\PurchaseChannel;

describe('PurchaseChannel', function () {

    it('requires only a supplier from a supplier trade', function () {
        expect(PurchaseChannel::SupplierTrade->requiresSupplier())->toBeTrue()
            ->and(PurchaseChannel::SupplierTrade->requiresBundle())->toBeFalse();
    });

    it('requires only a bundle from a direct bundle store purchase', function () {
        expect(PurchaseChannel::BundleStore->requiresSupplier())->toBeFalse()
            ->and(PurchaseChannel::BundleStore->requiresBundle())->toBeTrue();
    });

    it('requires no counterpart from a Gamivo purchase', function () {
        expect(PurchaseChannel::Gamivo->requiresSupplier())->toBeFalse()
            ->and(PurchaseChannel::Gamivo->requiresBundle())->toBeFalse();
    });
});

describe('PurchaseChannel::keySource', function () {

    it('uses the supplier profile url for a supplier trade', function () {
        expect(PurchaseChannel::SupplierTrade->keySource('https://steamcommunity.com/id/seller', 'Humble Choice'))
            ->toBe('https://steamcommunity.com/id/seller');
    });

    it('uses the bundle name for a direct bundle store purchase', function () {
        expect(PurchaseChannel::BundleStore->keySource(null, 'Humble Choice September'))
            ->toBe('Humble Choice September');
    });

    it('never leaves the source blank when the bundle has no name', function () {
        expect(PurchaseChannel::BundleStore->keySource(null, null))->toBe(PurchaseChannel::UNNAMED_BUNDLE_SOURCE)
            ->and(PurchaseChannel::BundleStore->keySource(null, '  '))->toBe(PurchaseChannel::UNNAMED_BUNDLE_SOURCE);
    });

    it('writes Gamivo for a Gamivo purchase', function () {
        expect(PurchaseChannel::Gamivo->keySource('https://steamcommunity.com/id/seller', null))
            ->toBe(PurchaseChannel::GAMIVO_SOURCE);
    });
});
