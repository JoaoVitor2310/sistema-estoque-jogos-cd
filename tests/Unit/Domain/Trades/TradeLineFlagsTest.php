<?php

use App\Domain\Trades\TradeLineFlags;

describe('TradeLineFlags::of()', function () {

    it('reads every flag the game carries', function () {
        expect(TradeLineFlags::of(['name' => 'Portal', 'is_overstocked' => true, 'is_giveaway' => false]))
            ->toBe(['is_overstocked' => true, 'is_giveaway' => false]);
    });

    it('treats a flag the game does not carry as false', function () {
        // Jogo que não passou pela avaliação nasce sem aviso.
        expect(TradeLineFlags::of(['name' => 'Portal']))->toBe(['is_overstocked' => false, 'is_giveaway' => false]);
    });
});

describe('TradeLineFlags::isOfferable()', function () {

    it('offers a game without any flag', function () {
        expect(TradeLineFlags::isOfferable(['is_overstocked' => false, 'is_giveaway' => false]))->toBeTrue()
            ->and(TradeLineFlags::isOfferable([]))->toBeTrue();
    });

    it('leaves out a game with any flag', function (array $game) {
        expect(TradeLineFlags::isOfferable($game))->toBeFalse();
    })->with([
        'overstocked' => [['is_overstocked' => true, 'is_giveaway' => false]],
        'in giveaway' => [['is_overstocked' => false, 'is_giveaway' => true]],
        'both' => [['is_overstocked' => true, 'is_giveaway' => true]],
    ]);
});
