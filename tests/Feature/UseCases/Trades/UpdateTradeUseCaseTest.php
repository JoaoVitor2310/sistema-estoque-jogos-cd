<?php

use App\Models\Trade;
use App\UseCases\Trades\UpdateTradeUseCase;

describe('UpdateTradeUseCase', function () {

    it('persists tf2_qty as provided', function () {
        $trade = Trade::create(['date' => now()->toDateString(), 'games' => []]);

        app(UpdateTradeUseCase::class)->execute($trade, [
            'tf2Qty' => '12.5',
        ]);

        expect($trade->fresh()->tf2_qty)->toBe('12.50');
    });

    it('stores null tf2_qty when not provided', function () {
        $trade = Trade::create(['date' => now()->toDateString(), 'tf2_qty' => '10.00', 'games' => []]);

        app(UpdateTradeUseCase::class)->execute($trade, []);

        expect($trade->fresh()->tf2_qty)->toBeNull();
    });
});
