<?php

/*
|--------------------------------------------------------------------------
| UpdateTradeUseCase — campos da própria trade
|--------------------------------------------------------------------------
|
| O contrato HTTP (validação, resposta com bundle_id) vive em
| tests/Feature/Trades/UpdateTradeTest.php. Aqui: o que o UseCase grava,
| inclusive a contraparte que o canal de compra mantém ou descarta.
|
*/

use App\Domain\Enums\PurchaseChannel;
use App\Models\Bundle;
use App\Models\Trade;
use App\UseCases\Trades\UpdateTradeUseCase;
use Illuminate\Support\Facades\DB;

describe('UpdateTradeUseCase', function () {

    it('persists tf2_qty as provided', function () {
        $trade = Trade::create(['date' => now()->toDateString()]);

        app(UpdateTradeUseCase::class)->execute($trade, [
            'tf2Qty' => '12.5',
        ]);

        expect($trade->fresh()->tf2_qty)->toBe('12.50');
    });

    it('stores null tf2_qty when not provided', function () {
        $trade = Trade::create(['date' => now()->toDateString(), 'tf2_qty' => '10.00']);

        app(UpdateTradeUseCase::class)->execute($trade, []);

        expect($trade->fresh()->tf2_qty)->toBeNull();
    });
});

describe('UpdateTradeUseCase — purchase channel', function () {

    it('stores a supplier trade when no channel is given', function () {
        $trade = Trade::create(['date' => now()->toDateString(), 'purchase_channel' => 'gamivo']);

        app(UpdateTradeUseCase::class)->execute($trade, []);

        expect($trade->fresh()->purchase_channel)->toBe(PurchaseChannel::SupplierTrade);
    });

    it('links a direct purchase to the bundle named by the title', function () {
        $bundle = Bundle::create(['name' => 'Humble Choice September']);
        $trade = Trade::create(['date' => now()->toDateString()]);

        app(UpdateTradeUseCase::class)->execute($trade, [
            'purchaseChannel' => 'bundle_store',
            'title' => 'Humble Choice September',
        ]);

        expect($trade->fresh()->bundle_id)->toBe($bundle->id);
    });

    it('leaves a direct purchase without bundle when the title names none', function () {
        Bundle::create(['name' => 'Humble Choice September']);
        $trade = Trade::create(['date' => now()->toDateString()]);

        app(UpdateTradeUseCase::class)->execute($trade, [
            'purchaseChannel' => 'bundle_store',
            'title' => 'Humble Choice',
        ]);

        expect($trade->fresh()->bundle_id)->toBeNull();
    });

    it('drops the supplier of a direct purchase without creating one from the leftover field', function () {
        Bundle::create(['name' => 'Humble Choice September']);
        $supplierId = DB::table('suppliers')->insertGetId(['url' => 'https://steamcommunity.com/id/seller', 'created_at' => now(), 'updated_at' => now()]);
        $trade = Trade::create(['date' => now()->toDateString(), 'supplier_id' => $supplierId]);

        app(UpdateTradeUseCase::class)->execute($trade, [
            'purchaseChannel' => 'bundle_store',
            'title' => 'Humble Choice September',
            'supplierUrl' => 'Humble Choice September',
        ]);

        expect($trade->fresh()->supplier_id)->toBeNull()
            ->and(DB::table('suppliers')->where('url', 'Humble Choice September')->exists())->toBeFalse();
    });

    it('drops the bundle of a supplier trade even when the title names one', function () {
        $bundle = Bundle::create(['name' => 'Humble Choice September']);
        $trade = Trade::create(['date' => now()->toDateString(), 'bundle_id' => $bundle->id]);

        app(UpdateTradeUseCase::class)->execute($trade, [
            'purchaseChannel' => 'supplier_trade',
            'title' => 'Humble Choice September',
            'supplierUrl' => 'https://steamcommunity.com/id/seller',
        ]);

        $trade->refresh();
        expect($trade->bundle_id)->toBeNull()
            ->and($trade->supplier?->url)->toBe('https://steamcommunity.com/id/seller');
    });

    it('keeps neither supplier nor bundle on a Gamivo purchase', function () {
        Bundle::create(['name' => 'Humble Choice September']);
        $trade = Trade::create(['date' => now()->toDateString()]);

        app(UpdateTradeUseCase::class)->execute($trade, [
            'purchaseChannel' => 'gamivo',
            'title' => 'Humble Choice September',
            'supplierUrl' => 'https://steamcommunity.com/id/seller',
        ]);

        $trade->refresh();
        expect($trade->purchase_channel)->toBe(PurchaseChannel::Gamivo)
            ->and($trade->supplier_id)->toBeNull()
            ->and($trade->bundle_id)->toBeNull()
            ->and(DB::table('suppliers')->count())->toBe(0);
    });
});
