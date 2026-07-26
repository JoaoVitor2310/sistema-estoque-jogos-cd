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

    it('seeds one empty row when games is not provided', function () {
        $trade = app(CreateTradeUseCase::class)->execute([]);

        expect($trade->games)->toHaveCount(1)
            ->and($trade->games[0])->toMatchArray([
                'name' => '',
                'marketPriceRaw' => '',
                'keyCode' => '',
            ]);
    });

    it('respects explicit games array (does not add a seed row)', function () {
        $trade = app(CreateTradeUseCase::class)->execute([
            'games' => [
                ['name' => 'Half-Life', 'marketPriceRaw' => '5.00', 'keyCode' => 'AAA'],
            ],
        ]);

        expect($trade->games)->toHaveCount(1)
            ->and($trade->games[0]['name'])->toBe('Half-Life');
    });
});
