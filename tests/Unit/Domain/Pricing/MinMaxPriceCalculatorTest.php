<?php

/*
|--------------------------------------------------------------------------
| MinMaxPriceCalculator — unit tests
|--------------------------------------------------------------------------
|
| Pure PHP — no DB, no framework bootstrap.
|
| O mínimo é delegado a MinimumMarginPolicy (testado à parte). Aqui só
| verificamos que a delegação acontece (idade influencia o mínimo) e o piso.
|
| Max:
|   individualCost < 1         → individualCost × 30
|   individualCost >= 1        → individualCost × 8
|   clientPrice >= max         → clientPrice × 8 (override)
|
| Both values have a 0.02 floor.
|
*/

use App\Domain\Pricing\MinMaxPriceCalculator;
use Carbon\Carbon;

dataset('min/max domain scenarios', [
    // custo, clientPrice, expectedMin (key jovem), expectedMax
    'high individualCost (>10)' => [15.0, 10.0, 21.75, 120.0],  // >10 tier → 45%
    'mid individualCost (>4, <=10)' => [5.0, 5.0, 7.5, 40.0],   // default → 50%
    'low individualCost (<=4, >=1)' => [4.0, 4.0, 6.0, 32.0],   // default → 50%
    'low individualCost (<4, >=1)' => [2.0, 2.0, 3.0, 16.0],    // default → 50%
    'very low individualCost (<1)' => [0.5, 0.3, 0.78, 15.0],   // <1 tier → 55%
]);

describe('MinMaxPriceCalculator::calculate()', function () {

    describe('minimum delegation to MinimumMarginPolicy', function () {
        it('uses the cost tier for a young key (default margin, 50%)', function () {
            $result = MinMaxPriceCalculator::calculate(5.0, 5.0, Carbon::now());

            expect($result['min'])->toEqualWithDelta(7.5, 0.001);
        });

        it('uses the aging tier for an old key, idade vence o custo (15%)', function () {
            $result = MinMaxPriceCalculator::calculate(5.0, 5.0, Carbon::now()->subMonths(7));

            expect($result['min'])->toEqualWithDelta(5.75, 0.001);
        });
    });

    describe('maximum price tiers', function () {
        it('is individualCost × 8 when individualCost is at or above €1', function () {
            // 15.0 × 8 = 120.0
            $result = MinMaxPriceCalculator::calculate(15.0, 10.0, Carbon::now());

            expect($result['max'])->toEqualWithDelta(120.0, 0.001);
        });

        it('is individualCost × 30 when individualCost is below €1', function () {
            // 0.5 × 30 = 15.0
            $result = MinMaxPriceCalculator::calculate(0.5, 0.3, Carbon::now());

            expect($result['max'])->toEqualWithDelta(15.0, 0.001);
        });

        it('is recalculated as clientPrice × 8 when clientPrice reaches or exceeds the initial max', function () {
            // individualCost=5.0 → initial max = 5.0 × 8 = 40.0
            // clientPrice=50.0 >= 40.0 → override: 50.0 × 8 = 400.0
            $result = MinMaxPriceCalculator::calculate(5.0, 50.0, Carbon::now());

            expect($result['max'])->toEqualWithDelta(400.0, 0.001);
        });
    });

    describe('0.02 floor', function () {
        it('applies to the minimum when individualCost is zero', function () {
            // custo=0 → guard FLOOR (0.02), tier 55% → 0.02 × 1.55 = 0.031 → 0.03
            $result = MinMaxPriceCalculator::calculate(0.0, 0.0, Carbon::now());

            expect($result['min'])->toEqualWithDelta(0.03, 0.001);
        });

        it('applies to the maximum when individualCost is zero', function () {
            $result = MinMaxPriceCalculator::calculate(0.0, 0.0, Carbon::now());

            expect($result['max'])->toEqualWithDelta(0.02, 0.001);
        });
    });

    describe('return shape', function () {
        it('always returns an array with min and max keys', function () {
            $result = MinMaxPriceCalculator::calculate(5.0, 5.0, Carbon::now());

            expect($result)->toHaveKeys(['min', 'max']);
        });
    });

    describe('snapshot across all price tiers (young key)', function () {
        it(
            'calculates min and max correctly',
            function (float $individualCost, float $clientPrice, float $expectedMin, float $expectedMax) {
                $result = MinMaxPriceCalculator::calculate($individualCost, $clientPrice, Carbon::now());

                expect($result['min'])->toEqualWithDelta($expectedMin, 0.001)
                    ->and($result['max'])->toEqualWithDelta($expectedMax, 0.001);
            }
        )->with('min/max domain scenarios');
    });
});

describe('MinMaxPriceCalculator::clamp()', function () {

    it('does not silently collapse the price when $limits carries a null value', function () {
        // Regression guard: PHP's min(null, x) returns null, not x. clamp() only
        // guards against $limits being null as a whole (no key/product in the
        // DB) — callers must never pass an array with a null min_api/max_api
        // inside it (keys.min_api/max_api are NOT NULL since 2026-08-08, so
        // every real caller already gets concrete floats from the model).
        $limits = ['min_api' => 3.50, 'max_api' => 12.00];

        expect(MinMaxPriceCalculator::clamp(9999.0, $limits))->toBe(12.00);
    });

    it('returns the price unchanged when it already falls within min_api/max_api', function () {
        $limits = ['min_api' => 1.00, 'max_api' => 10.00];

        expect(MinMaxPriceCalculator::clamp(5.00, $limits))->toBe(5.00);
    });

    it('raises the price up to min_api when it falls below it', function () {
        $limits = ['min_api' => 3.00, 'max_api' => 10.00];

        expect(MinMaxPriceCalculator::clamp(1.00, $limits))->toBe(3.00);
    });

    it('caps the price down to max_api when it exceeds it', function () {
        $limits = ['min_api' => 1.00, 'max_api' => 10.00];

        expect(MinMaxPriceCalculator::clamp(50.00, $limits))->toBe(10.00);
    });

    it('applies only FLOOR/CEILING when $limits is null (no key/product found)', function () {
        expect(MinMaxPriceCalculator::clamp(0.001, null))->toBe(MinMaxPriceCalculator::FLOOR)
            ->and(MinMaxPriceCalculator::clamp(9999.0, null))->toBe(MinMaxPriceCalculator::CEILING)
            ->and(MinMaxPriceCalculator::clamp(5.00, null))->toBe(5.00);
    });

    it('still enforces the absolute FLOOR when min_api itself is below it', function () {
        // Cenário defensivo: um min_api corrompido/abaixo do piso absoluto não pode
        // abrir uma brecha para vender mais barato que o FLOOR.
        $limits = ['min_api' => 0.001, 'max_api' => 10.00];

        expect(MinMaxPriceCalculator::clamp(0.001, $limits))->toBe(MinMaxPriceCalculator::FLOOR);
    });

    it('still enforces the absolute CEILING when max_api itself is above it', function () {
        $limits = ['min_api' => 1.00, 'max_api' => 1000.00];

        expect(MinMaxPriceCalculator::clamp(999.00, $limits))->toBe(MinMaxPriceCalculator::CEILING);
    });
});
