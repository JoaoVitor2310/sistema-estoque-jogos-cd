<?php

/*
|--------------------------------------------------------------------------
| MinMaxPriceCalculator — unit tests
|--------------------------------------------------------------------------
|
| Pure PHP — no DB, no framework bootstrap.
|
| Rules:
|   Min:
|     individualCost > 10  → individualCost × 1.4  (+40%)
|     individualCost > 4   → individualCost × 1.5  (+50%)
|     demais               → individualCost × 1.6  (+60%)
|
|   Max:
|     individualCost < 1         → individualCost × 30
|     individualCost >= 1        → individualCost × 8
|     clientPrice >= max         → clientPrice × 8 (override)
|
|   Both values have a 0.02 floor.
|
*/

use App\Domain\Pricing\MinMaxPriceCalculator;

dataset('min/max domain scenarios', [
    'high individualCost (>10)'    => [15.0, 10.0,  21.0, 120.0],
    'mid individualCost (>4, <=10)' => [ 5.0,  5.0,   7.5,  40.0],
    'low individualCost (<=4, >=1)' => [ 4.0,  4.0,   6.4,  32.0],
    'low individualCost (<4, >=1)'  => [ 2.0,  2.0,   3.2,  16.0],
    'very low individualCost (<1)'  => [ 0.5,  0.3,   0.8,  15.0],
]);

describe('MinMaxPriceCalculator::calculate()', function () {

    describe('minimum price tiers', function () {
        it('is individualCost × 1.4 when individualCost is above €10', function () {
            // 15.0 × 1.4 = 21.0
            $result = MinMaxPriceCalculator::calculate(15.0, 10.0);

            expect($result['min'])->toEqualWithDelta(21.0, 0.001);
        });

        it('is individualCost × 1.5 when individualCost is above €4', function () {
            // 5.0 × 1.5 = 7.5
            $result = MinMaxPriceCalculator::calculate(5.0, 5.0);

            expect($result['min'])->toEqualWithDelta(7.5, 0.001);
        });

        it('is individualCost × 1.6 for any value at or below €4', function () {
            // 4.0 × 1.6 = 6.4
            $result = MinMaxPriceCalculator::calculate(4.0, 4.0);

            expect($result['min'])->toEqualWithDelta(6.4, 0.001);
        });

        it('is individualCost × 1.6 when individualCost is below €4', function () {
            // 2.0 × 1.6 = 3.2
            $result = MinMaxPriceCalculator::calculate(2.0, 2.0);

            expect($result['min'])->toEqualWithDelta(3.2, 0.001);
        });

        it('is individualCost × 1.6 when individualCost is very low (below €1)', function () {
            // 0.5 × 1.6 = 0.8
            $result = MinMaxPriceCalculator::calculate(0.5, 0.3);

            expect($result['min'])->toEqualWithDelta(0.8, 0.001);
        });

        it('applies the +50% tier at exactly the €4 boundary (> 4 is false)', function () {
            // 4.0 is NOT > 4, so falls into the default ×1.6 tier
            $result = MinMaxPriceCalculator::calculate(4.0, 4.0);

            expect($result['min'])->toEqualWithDelta(6.4, 0.001);
        });

        it('applies the +50% tier just above €4', function () {
            // 4.01 × 1.5 = 6.015
            $result = MinMaxPriceCalculator::calculate(4.01, 4.01);

            expect($result['min'])->toEqualWithDelta(6.015, 0.001);
        });
    });

    describe('maximum price tiers', function () {
        it('is individualCost × 8 when individualCost is at or above €1', function () {
            // 15.0 × 8 = 120.0
            $result = MinMaxPriceCalculator::calculate(15.0, 10.0);

            expect($result['max'])->toEqualWithDelta(120.0, 0.001);
        });

        it('is individualCost × 30 when individualCost is below €1', function () {
            // 0.5 × 30 = 15.0
            $result = MinMaxPriceCalculator::calculate(0.5, 0.3);

            expect($result['max'])->toEqualWithDelta(15.0, 0.001);
        });

        it('is recalculated as clientPrice × 8 when clientPrice reaches or exceeds the initial max', function () {
            // individualCost=5.0 → initial max = 5.0 × 8 = 40.0
            // clientPrice=50.0 >= 40.0 → override: 50.0 × 8 = 400.0
            $result = MinMaxPriceCalculator::calculate(5.0, 50.0);

            expect($result['max'])->toEqualWithDelta(400.0, 0.001);
        });
    });

    describe('0.02 floor', function () {
        it('applies to the minimum when individualCost is zero', function () {
            $result = MinMaxPriceCalculator::calculate(0.0, 0.0);

            expect($result['min'])->toEqualWithDelta(0.02, 0.001);
        });

        it('applies to the maximum when individualCost is zero', function () {
            $result = MinMaxPriceCalculator::calculate(0.0, 0.0);

            expect($result['max'])->toEqualWithDelta(0.02, 0.001);
        });
    });

    describe('return shape', function () {
        it('always returns an array with min and max keys', function () {
            $result = MinMaxPriceCalculator::calculate(5.0, 5.0);

            expect($result)->toHaveKeys(['min', 'max']);
        });
    });

    describe('snapshot across all price tiers', function () {
        it(
            'calculates min and max correctly',
            function (float $individualCost, float $clientPrice, float $expectedMin, float $expectedMax) {
                $result = MinMaxPriceCalculator::calculate($individualCost, $clientPrice);

                expect($result['min'])->toEqualWithDelta($expectedMin, 0.001)
                    ->and($result['max'])->toEqualWithDelta($expectedMax, 0.001);
            }
        )->with('min/max domain scenarios');
    });
});
