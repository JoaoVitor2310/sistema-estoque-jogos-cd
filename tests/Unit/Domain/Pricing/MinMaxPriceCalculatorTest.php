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
| soleSellerPrice() cobre o caso sem concorrente, onde o teto de valorização
| deixa de ter freio: ali a âncora é o market_price, não o custo.
|
*/

use App\Domain\Enums\PurchaseChannel;
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
            $result = MinMaxPriceCalculator::calculate(5.0, 5.0, Carbon::now(), PurchaseChannel::SupplierTrade);

            expect($result['min'])->toEqualWithDelta(7.5, 0.001);
        });

        it('uses the bundle store margin for a young direct purchase (40%)', function () {
            // 5.00 × 1.40 = 7.00, contra 7.50 da faixa default de fornecedor
            $result = MinMaxPriceCalculator::calculate(5.0, 5.0, Carbon::now(), PurchaseChannel::BundleStore);

            expect($result['min'])->toEqualWithDelta(7.0, 0.001);
        });

        it('uses the aging tier for an old key, idade vence o custo (15%)', function () {
            $result = MinMaxPriceCalculator::calculate(5.0, 5.0, Carbon::now()->subMonths(7), PurchaseChannel::SupplierTrade);

            expect($result['min'])->toEqualWithDelta(5.75, 0.001);
        });
    });

    describe('maximum price tiers', function () {
        it('is individualCost × 8 when individualCost is at or above €1', function () {
            // 15.0 × 8 = 120.0
            $result = MinMaxPriceCalculator::calculate(15.0, 10.0, Carbon::now(), PurchaseChannel::SupplierTrade);

            expect($result['max'])->toEqualWithDelta(120.0, 0.001);
        });

        it('is individualCost × 30 when individualCost is below €1', function () {
            // 0.5 × 30 = 15.0
            $result = MinMaxPriceCalculator::calculate(0.5, 0.3, Carbon::now(), PurchaseChannel::SupplierTrade);

            expect($result['max'])->toEqualWithDelta(15.0, 0.001);
        });

        it('is recalculated as clientPrice × 8 when clientPrice reaches or exceeds the initial max', function () {
            // individualCost=5.0 → initial max = 5.0 × 8 = 40.0
            // clientPrice=50.0 >= 40.0 → override: 50.0 × 8 = 400.0
            $result = MinMaxPriceCalculator::calculate(5.0, 50.0, Carbon::now(), PurchaseChannel::SupplierTrade);

            expect($result['max'])->toEqualWithDelta(400.0, 0.001);
        });
    });

    describe('0.02 floor', function () {
        it('applies to the minimum when individualCost is zero', function () {
            // custo=0 → guard FLOOR (0.02), tier 55% → 0.02 × 1.55 = 0.031 → 0.03
            $result = MinMaxPriceCalculator::calculate(0.0, 0.0, Carbon::now(), PurchaseChannel::SupplierTrade);

            expect($result['min'])->toEqualWithDelta(0.03, 0.001);
        });

        it('applies to the maximum when individualCost is zero', function () {
            $result = MinMaxPriceCalculator::calculate(0.0, 0.0, Carbon::now(), PurchaseChannel::SupplierTrade);

            expect($result['max'])->toEqualWithDelta(0.02, 0.001);
        });
    });

    describe('return shape', function () {
        it('always returns an array with min and max keys', function () {
            $result = MinMaxPriceCalculator::calculate(5.0, 5.0, Carbon::now(), PurchaseChannel::SupplierTrade);

            expect($result)->toHaveKeys(['min', 'max']);
        });
    });

    describe('snapshot across all price tiers (young key)', function () {
        it(
            'calculates min and max correctly',
            function (float $individualCost, float $clientPrice, float $expectedMin, float $expectedMax) {
                $result = MinMaxPriceCalculator::calculate($individualCost, $clientPrice, Carbon::now(), PurchaseChannel::SupplierTrade);

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

describe('MinMaxPriceCalculator::soleSellerPrice()', function () {

    it('prices at the multiplier over the researched market price', function () {
        // 20.00 × 1.10 = 22.00, entre o min_api e o max_api → o teto de mercado manda
        expect(MinMaxPriceCalculator::soleSellerPrice(20.00, 5.00, 160.00))->toBe(22.00);
    });

    it('rounds to cents', function () {
        // 4.55 × 1.10 = 5.005 → 5.01 (não 5.005, que a Gamivo rejeitaria)
        expect(MinMaxPriceCalculator::soleSellerPrice(4.55, 1.00, 30.00))->toBe(5.01);
    });

    it('lets min_api win when the market ceiling falls below the floor', function () {
        // Trade ruim: pagamos €4 num jogo que o mercado pesquisou a €4,50.
        // min_api = 4 × 1.5 = 6.00; teto de mercado = 4.50 × 1.10 = 4.95.
        // Sem concorrente ninguém nos corta, então não há motivo para furar a margem.
        expect(MinMaxPriceCalculator::soleSellerPrice(4.50, 6.00, 32.00))->toBe(6.00);
    });

    it('caps at max_api when it sits below the market ceiling', function () {
        // 9.09 × 1.10 = 10.00, mas o max_api está em 8.00 — teto editado à mão na tela
        // de Keys, ou travado pelo auto-sell numa key velha. A coluna tem que significar
        // teto também aqui, senão a tela promete um limite que a precificação ignora.
        expect(MinMaxPriceCalculator::soleSellerPrice(9.09, 3.00, 8.00))->toBe(8.00);
    });

    it('keeps the market ceiling when max_api sits above it', function () {
        // Caso normal: a fórmula do import deixa o max_api bem acima do mercado,
        // então ele não binda e quem manda é o teto de mercado.
        expect(MinMaxPriceCalculator::soleSellerPrice(20.00, 5.00, 160.00))
            ->toBe(MinMaxPriceCalculator::soleSellerPrice(20.00, 5.00, 22.00));
    });

    it('still lets min_api win over a max_api below it', function () {
        // Piso vence teto, mesma regra do clamp do fluxo com concorrente.
        expect(MinMaxPriceCalculator::soleSellerPrice(9.09, 12.00, 8.00))->toBe(12.00);
    });

    it('never returns below the absolute FLOOR even without a market price', function () {
        // market_price ausente/zerado não pode virar preço zero na Gamivo
        expect(MinMaxPriceCalculator::soleSellerPrice(0.0, 0.0, 0.0))->toBe(MinMaxPriceCalculator::FLOOR);
    });

    it('still enforces the absolute CEILING for an extreme market price', function () {
        expect(MinMaxPriceCalculator::soleSellerPrice(10_000.00, 1.00, 100_000.00))
            ->toBe(MinMaxPriceCalculator::CEILING);
    });

    it('stays far below the appreciation ceiling that max_api would have produced', function () {
        // É o bug que motivou a regra: custo €1 e mercado €20 dão max_api = 20 × 8 = 160,
        // e sem concorrente esse teto virava o preço praticado.
        $maxApi = MinMaxPriceCalculator::calculate(1.00, 20.00, Carbon::now(), PurchaseChannel::SupplierTrade)['max'];

        expect($maxApi)->toBe(160.0)
            ->and(MinMaxPriceCalculator::soleSellerPrice(20.00, 1.50, $maxApi))->toBe(22.00);
    });
});
