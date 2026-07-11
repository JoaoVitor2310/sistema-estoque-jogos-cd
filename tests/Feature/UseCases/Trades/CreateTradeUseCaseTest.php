<?php

use App\UseCases\Trades\CreateTradeUseCase;

describe('CreateTradeUseCase', function () {

    it('persists tf2_qty as provided', function () {
        $trade = app(CreateTradeUseCase::class)->execute([
            'tf2Qty' => '12.5',
        ]);

        expect($trade->tf2_qty)->toBe('12.50');
    });

    it('stores null tf2_qty when not provided', function () {
        $trade = app(CreateTradeUseCase::class)->execute([]);

        expect($trade->tf2_qty)->toBeNull();
    });
});
