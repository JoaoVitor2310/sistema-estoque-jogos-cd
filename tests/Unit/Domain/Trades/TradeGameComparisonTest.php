<?php

use App\Domain\Trades\TradeGameComparison;

describe('TradeGameComparison::hasChanged()', function () {

    it('returns false when game names are identical', function () {
        $current = [['name' => 'Half-Life'], ['name' => 'Portal']];
        $previous = [['name' => 'Half-Life'], ['name' => 'Portal']];

        expect(TradeGameComparison::hasChanged($current, $previous))->toBeFalse();
    });

    it('returns false regardless of order', function () {
        $current = [['name' => 'Portal'], ['name' => 'Half-Life']];
        $previous = [['name' => 'Half-Life'], ['name' => 'Portal']];

        expect(TradeGameComparison::hasChanged($current, $previous))->toBeFalse();
    });

    it('returns true when a game is added', function () {
        $current = [['name' => 'Half-Life'], ['name' => 'Portal'], ['name' => 'Left 4 Dead']];
        $previous = [['name' => 'Half-Life'], ['name' => 'Portal']];

        expect(TradeGameComparison::hasChanged($current, $previous))->toBeTrue();
    });

    it('returns true when a game is removed', function () {
        $current = [['name' => 'Half-Life']];
        $previous = [['name' => 'Half-Life'], ['name' => 'Portal']];

        expect(TradeGameComparison::hasChanged($current, $previous))->toBeTrue();
    });

    it('returns true when a game name is replaced', function () {
        $current = [['name' => 'Half-Life'], ['name' => 'Counter-Strike']];
        $previous = [['name' => 'Half-Life'], ['name' => 'Portal']];

        expect(TradeGameComparison::hasChanged($current, $previous))->toBeTrue();
    });

    it('returns true when lists have different sizes', function () {
        $current = [['name' => 'Half-Life']];
        $previous = [];

        expect(TradeGameComparison::hasChanged($current, $previous))->toBeTrue();
    });

    it('returns false when both lists are empty', function () {
        expect(TradeGameComparison::hasChanged([], []))->toBeFalse();
    });
});
