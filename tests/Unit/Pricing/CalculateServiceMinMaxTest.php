<?php

/*
|--------------------------------------------------------------------------
| KeyCalculationService::calculateMinMaxApi() — characterization tests
|--------------------------------------------------------------------------
|
| Pure math — no DB calls (KeyCalculationService::calculateMinMaxApi
| delegates directly to MinMaxPriceCalculator with no DB dependency).
|
| Rules:
|   Min:
|     valorPago > 10  → valorPago × 1.4  (+40%)
|     valorPago > 4   → valorPago × 1.5  (+50%)
|     demais          → valorPago × 1.6  (+60%)
|
|   Max:
|     valorPago < 1         → valorPago × 30
|     valorPago >= 1        → valorPago × 8
|     precoCliente >= max   → precoCliente × 8  (override)
|
|   Both values have a 0.02 floor.
|
*/

use App\Services\Keys\KeyCalculationService;

dataset('min/max price scenarios', [
    'high valorPago (>10)' => [15.0, 10.0,  21.0, 120.0],
    'mid valorPago (>4, <=10)' => [5.0,  5.0,   7.5,  40.0],
    'low valorPago (<=4, >=1)' => [4.0,  4.0,   6.4,  32.0],
    'low valorPago (<4, >=1)' => [2.0,  2.0,   3.2,  16.0],
    'very low valorPago (<1)' => [0.5,  0.3,   0.8,  15.0],
]);

describe('CalculateService::calculateMinMaxApi()', function () {

    beforeEach(function () {
        $this->service = new KeyCalculationService;
    });

    $game = fn (float $valorPago, float $precoCliente) => [
        'individual_cost' => $valorPago,
        'market_price' => $precoCliente,
    ];

    describe('minimum price tiers', function () use ($game) {
        it('is valorPago × 1.4 when valorPago is above €10', function () use ($game) {
            $result = $this->service->calculateMinMaxApi($game(15.0, 10.0));

            expect($result['minApiGamivo'])->toEqualWithDelta(21.0, 0.001);
        });

        it('is valorPago × 1.5 when valorPago is above €4', function () use ($game) {
            $result = $this->service->calculateMinMaxApi($game(5.0, 5.0));

            expect($result['minApiGamivo'])->toEqualWithDelta(7.5, 0.001);
        });

        it('is valorPago × 1.6 for any value at or below €4', function () use ($game) {
            // 4.0 is NOT > 4, falls into default ×1.6
            $result = $this->service->calculateMinMaxApi($game(4.0, 4.0));

            expect($result['minApiGamivo'])->toEqualWithDelta(6.4, 0.001);
        });

        it('is valorPago × 1.6 when valorPago is below €4', function () use ($game) {
            $result = $this->service->calculateMinMaxApi($game(2.0, 2.0));

            expect($result['minApiGamivo'])->toEqualWithDelta(3.2, 0.001);
        });

        it('is valorPago × 1.6 when valorPago is very low (below €1)', function () use ($game) {
            $result = $this->service->calculateMinMaxApi($game(0.5, 0.3));

            expect($result['minApiGamivo'])->toEqualWithDelta(0.8, 0.001);
        });
    });

    describe('maximum price tiers', function () use ($game) {
        it('is valorPago × 8 when valorPago is at or above €1', function () use ($game) {
            $result = $this->service->calculateMinMaxApi($game(15.0, 10.0));

            expect($result['maxApiGamivo'])->toEqualWithDelta(120.0, 0.001);
        });

        it('is valorPago × 30 when valorPago is below €1', function () use ($game) {
            $result = $this->service->calculateMinMaxApi($game(0.5, 0.3));

            expect($result['maxApiGamivo'])->toEqualWithDelta(15.0, 0.001);
        });

        it('is recalculated as precoCliente × 8 when precoCliente reaches or exceeds the initial max', function () use ($game) {
            $result = $this->service->calculateMinMaxApi($game(5.0, 50.0));

            expect($result['maxApiGamivo'])->toEqualWithDelta(400.0, 0.001);
        });
    });

    describe('0.02 floor', function () use ($game) {
        it('applies to the minimum when the calculated value would be zero', function () use ($game) {
            $result = $this->service->calculateMinMaxApi($game(0, 0));

            expect($result['minApiGamivo'])->toEqualWithDelta(0.02, 0.001);
        });

        it('applies to the maximum when the calculated value would be zero', function () use ($game) {
            $result = $this->service->calculateMinMaxApi($game(0, 0));

            expect($result['maxApiGamivo'])->toEqualWithDelta(0.02, 0.001);
        });
    });

    describe('snapshot across all price tiers', function () use ($game) {
        it(
            'calculates min and max correctly',
            function (float $valorPago, float $precoCliente, float $expectedMin, float $expectedMax) use ($game) {
                $result = $this->service->calculateMinMaxApi($game($valorPago, $precoCliente));

                expect($result['minApiGamivo'])->toEqualWithDelta($expectedMin, 0.001)
                    ->and($result['maxApiGamivo'])->toEqualWithDelta($expectedMax, 0.001);
            }
        )->with('min/max price scenarios');
    });
});
