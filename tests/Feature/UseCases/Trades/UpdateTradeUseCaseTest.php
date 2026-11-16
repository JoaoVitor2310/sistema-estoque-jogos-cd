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
use App\UseCases\Trades\CreateTradeUseCase;
use App\UseCases\Trades\UpdateTradeUseCase;
use Illuminate\Support\Facades\DB;

describe('UpdateTradeUseCase', function () {

    it('persists amount as provided', function () {
        $trade = Trade::create(['date' => now()->toDateString()]);

        app(UpdateTradeUseCase::class)->execute($trade, [
            'amount' => '12.5',
        ]);

        expect($trade->fresh()->amount)->toBe('12.50');
    });

    it('stores null amount when not provided', function () {
        $trade = Trade::create(['date' => now()->toDateString(), 'amount' => '10.00']);

        app(UpdateTradeUseCase::class)->execute($trade, []);

        expect($trade->fresh()->amount)->toBeNull();
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

describe('UpdateTradeUseCase — supplier trade number', function () {

    /** Uma trade criada pelo caminho de verdade, já numerada no supplier. */
    function numberedTrade(string $steamId): Trade
    {
        return app(CreateTradeUseCase::class)->execute(['supplierUrl' => "https://steamcommunity.com/profiles/{$steamId}"])->fresh();
    }

    it('gives the trade the next number of the new supplier', function () {
        $moved = numberedTrade('76561198000000701');
        numberedTrade('76561198000000702');
        numberedTrade('76561198000000702');

        app(UpdateTradeUseCase::class)->execute($moved, ['supplierUrl' => 'https://steamcommunity.com/profiles/76561198000000702']);

        expect($moved->fresh()->supplier_trade_number)->toBe(3);
    });

    it('does not collide when the new supplier already has the number of the trade', function () {
        // Supplier e número vão juntos no mesmo save: gravar o supplier novo
        // com o número antigo bateria no índice único (supplier, número).
        $moved = numberedTrade('76561198000000701');
        $existing = numberedTrade('76561198000000702');

        expect($moved->supplier_trade_number)->toBe(1)
            ->and($existing->supplier_trade_number)->toBe(1);

        app(UpdateTradeUseCase::class)->execute($moved, ['supplierUrl' => 'https://steamcommunity.com/profiles/76561198000000702']);

        expect($moved->fresh()->supplier_trade_number)->toBe(2);
    });

    it('does not give the old number back to the old supplier', function () {
        $moved = numberedTrade('76561198000000701');

        app(UpdateTradeUseCase::class)->execute($moved, ['supplierUrl' => 'https://steamcommunity.com/profiles/76561198000000702']);

        expect(numberedTrade('76561198000000701')->supplier_trade_number)->toBe(2);
    });

    it('keeps the number when the supplier stays the same', function () {
        $trade = numberedTrade('76561198000000701');

        app(UpdateTradeUseCase::class)->execute($trade, [
            'supplierUrl' => 'https://steamcommunity.com/profiles/76561198000000701',
            'title' => 'Renamed',
        ]);

        expect($trade->fresh()->supplier_trade_number)->toBe(1);
    });

    it('drops the number when the trade loses its supplier', function () {
        $trade = numberedTrade('76561198000000701');

        app(UpdateTradeUseCase::class)->execute($trade, ['purchaseChannel' => 'gamivo']);

        expect($trade->fresh()->supplier_trade_number)->toBeNull();
    });
});
