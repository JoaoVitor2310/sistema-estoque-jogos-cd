<?php

/*
|--------------------------------------------------------------------------
| KeyCalculationService::calculateMinMaxApi() — characterization tests
|--------------------------------------------------------------------------
|
| Pure math — no DB calls (KeyCalculationService::calculateMinMaxApi
| delegates directly to MinMaxPriceCalculator / MinimumMarginPolicy).
|
| O mínimo depende de custo E idade (via MinimumMarginPolicy). Aqui os jogos
| usam acquired_at = hoje (key jovem), então o mínimo cai nos tiers de custo:
|   valorPago > 15  → 40%
|   valorPago > 10  → 45%
|   valorPago < 1   → 55%
|   demais          → 60%
|
| Max:
|   valorPago < 1         → valorPago × 30
|   valorPago >= 1        → valorPago × 8
|   precoCliente >= max   → precoCliente × 8  (override)
|
|   Both values have a 0.02 floor.
|
*/

use App\Services\Keys\KeyCalculationService;

dataset('min/max price scenarios', [
    // valorPago, precoCliente, expectedMin (jovem), expectedMax
    'high valorPago (>10)' => [15.0, 10.0, 21.75, 120.0],  // >10 tier → 45%
    'mid valorPago (>4, <=10)' => [5.0, 5.0, 8.0, 40.0],    // default → 60%
    'low valorPago (<=4, >=1)' => [4.0, 4.0, 6.4, 32.0],    // default → 60%
    'low valorPago (<4, >=1)' => [2.0, 2.0, 3.2, 16.0],     // default → 60%
    'very low valorPago (<1)' => [0.5, 0.3, 0.78, 15.0],    // <1 tier → 55%
]);

describe('CalculateService::calculateMinMaxApi()', function () {

    beforeEach(function () {
        $this->service = new KeyCalculationService;
    });

    $game = fn (float $valorPago, float $precoCliente) => [
        'individual_cost' => $valorPago,
        'market_price' => $precoCliente,
        'acquired_at' => now()->toDateString(),
    ];

    describe('minimum price (young key → cost tiers)', function () use ($game) {
        it('is valorPago × 1.45 when valorPago is above €10', function () use ($game) {
            // 15 → 45% → 21.75
            $result = $this->service->calculateMinMaxApi($game(15.0, 10.0));

            expect($result['min_api'])->toEqualWithDelta(21.75, 0.001);
        });

        it('is valorPago × 1.6 in the default tier', function () use ($game) {
            // 5 → 60% → 8.0
            $result = $this->service->calculateMinMaxApi($game(5.0, 5.0));

            expect($result['min_api'])->toEqualWithDelta(8.0, 0.001);
        });

        it('is valorPago × 1.55 when valorPago is below €1', function () use ($game) {
            // 0.5 → 55% → 0.775 → 0.78
            $result = $this->service->calculateMinMaxApi($game(0.5, 0.3));

            expect($result['min_api'])->toEqualWithDelta(0.78, 0.001);
        });
    });

    describe('minimum price delegates aging to MinimumMarginPolicy', function () {
        it('applies the aging margin when the key is old (15%)', function () {
            $result = $this->service->calculateMinMaxApi([
                'individual_cost' => 5.0,
                'market_price' => 5.0,
                'acquired_at' => now()->subMonths(7)->toDateString(),
            ]);

            expect($result['min_api'])->toEqualWithDelta(5.75, 0.001);
        });
    });

    describe('maximum price tiers', function () use ($game) {
        it('is valorPago × 8 when valorPago is at or above €1', function () use ($game) {
            $result = $this->service->calculateMinMaxApi($game(15.0, 10.0));

            expect($result['max_api'])->toEqualWithDelta(120.0, 0.001);
        });

        it('is valorPago × 30 when valorPago is below €1', function () use ($game) {
            $result = $this->service->calculateMinMaxApi($game(0.5, 0.3));

            expect($result['max_api'])->toEqualWithDelta(15.0, 0.001);
        });

        it('is recalculated as precoCliente × 8 when precoCliente reaches or exceeds the initial max', function () use ($game) {
            $result = $this->service->calculateMinMaxApi($game(5.0, 50.0));

            expect($result['max_api'])->toEqualWithDelta(400.0, 0.001);
        });
    });

    describe('0.02 floor', function () use ($game) {
        it('applies the cost guard when the calculated value would be zero', function () use ($game) {
            // custo=0 → guard FLOOR (0.02), tier 55% → 0.02 × 1.55 = 0.031 → 0.03
            $result = $this->service->calculateMinMaxApi($game(0, 0));

            expect($result['min_api'])->toEqualWithDelta(0.03, 0.001);
        });

        it('applies to the maximum when the calculated value would be zero', function () use ($game) {
            $result = $this->service->calculateMinMaxApi($game(0, 0));

            expect($result['max_api'])->toEqualWithDelta(0.02, 0.001);
        });
    });

    describe('snapshot across all price tiers (young key)', function () use ($game) {
        it(
            'calculates min and max correctly',
            function (float $valorPago, float $precoCliente, float $expectedMin, float $expectedMax) use ($game) {
                $result = $this->service->calculateMinMaxApi($game($valorPago, $precoCliente));

                expect($result['min_api'])->toEqualWithDelta($expectedMin, 0.001)
                    ->and($result['max_api'])->toEqualWithDelta($expectedMax, 0.001);
            }
        )->with('min/max price scenarios');
    });
});
