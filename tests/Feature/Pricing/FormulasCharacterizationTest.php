<?php

/*
|--------------------------------------------------------------------------
| KeyCalculationService — characterization tests
|--------------------------------------------------------------------------
|
| Documenta o comportamento atual de App\Services\Keys\KeyCalculationService.
|
| Todos os métodos retornam floats — a formatação para exibição é
| responsabilidade da camada de apresentação. Por isso os asserts usam
| toEqualWithDelta em vez de toBe('string').
|
| Seeded rates (mirror production values):
|   gamivoPercentualMenor = 0.060  (6.0 %)
|   gamivoFixoMenor       = 0.250  (€ 0.25)
|   gamivoPercentualMaior = 0.080  (8.0 %)
|   gamivoFixoMaior       = 0.400  (€ 0.40)
|   TF2 price_euro        = 1.500  (€ 1.50 per TF2 key)
|
*/

use App\Services\Keys\KeyCalculationService;
use Illuminate\Support\Facades\DB;

describe('KeyCalculationService', function () {

    beforeEach(function () {
        DB::table('fees')->insert([
            ['name' => 'gamivoPercentualMenor', 'preco' => 0.060, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'gamivoFixoMenor',       'preco' => 0.250, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'gamivoPercentualMaior', 'preco' => 0.080, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'gamivoFixoMaior',       'preco' => 0.400, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('assets')->insert([
            ['name' => 'TF2', 'price_euro' => 1.500, 'price_dollar' => 1.600, 'price_brl' => 8.000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->service = new KeyCalculationService;
    });

    // -------------------------------------------------------------------------

    describe('calculateFirstFormulas() — income simulado Gamivo', function () {

        it('subtracts only the fixed fee when price is below €0.28', function () {
            // 0.20 - 0.11 = 0.09
            $result = $this->service->calculateFirstFormulas([['market_price' => 0.20]]);

            expect($result['games'][0]['simulated_income'])->toEqualWithDelta(0.09, 0.001);
        });

        it('applies percentage + fixed fee for the lower tier (€0.28–€8)', function () {
            // 3.00 × (1 - 0.060) - 0.25 = 2.57
            $result = $this->service->calculateFirstFormulas([['market_price' => 3.00]]);

            expect($result['games'][0]['simulated_income'])->toEqualWithDelta(2.57, 0.001);
        });

        it('applies a higher percentage + fixed fee for the upper tier (≥ €8)', function () {
            // 10.00 × (1 - 0.080) - 0.40 = 8.80
            $result = $this->service->calculateFirstFormulas([['market_price' => 10.00]]);

            expect($result['games'][0]['simulated_income'])->toEqualWithDelta(8.80, 0.001);
        });

        it('falls into the lower tier at exactly the €0.28 boundary', function () {
            // 0.28 × (1 - 0.060) - 0.25 = 0.0132
            $result = $this->service->calculateFirstFormulas([['market_price' => 0.28]]);

            expect($result['games'][0]['simulated_income'])->toEqualWithDelta(0.0132, 0.0001);
        });

        it('falls into the upper tier at exactly the €8 boundary', function () {
            // 8.00 × (1 - 0.080) - 0.40 = 6.96
            $result = $this->service->calculateFirstFormulas([['market_price' => 8.00]]);

            expect($result['games'][0]['simulated_income'])->toEqualWithDelta(6.96, 0.001);
        });

        it('accumulates the sum of incomes across the batch', function () {
            // 2.57 + 8.80 = 11.37
            $result = $this->service->calculateFirstFormulas([
                ['market_price' => 3.00],
                ['market_price' => 10.00],
            ]);

            expect($result['somatorioIncomes'])->toEqualWithDelta(11.37, 0.001);
        });
    });

    // -------------------------------------------------------------------------

    describe('calculateFormulas() — individual cost and profit', function () {

        it('calculates individualCost proportional to the simulated income', function () {
            // 2 × 1.50 / 5.00 × 2.67 = 1.602
            $game = ['tf2_quantity' => 2, 'simulated_income' => 2.67, 'individual_cost' => null, 'sold_price' => 0];

            $result = $this->service->calculateFormulas($game, somatorioIncomes: 5.00, isEdit: false);

            expect($result['individual_cost'])->toEqualWithDelta(1.602, 0.001);
        });

        it('calculates purchaseProfit (purchase_profit)', function () {
            // 2.67 - 1.602 = 1.068
            $game = ['tf2_quantity' => 2, 'simulated_income' => 2.67, 'individual_cost' => null, 'sold_price' => 0];

            $result = $this->service->calculateFormulas($game, somatorioIncomes: 5.00, isEdit: false);

            expect($result['purchase_profit'])->toEqualWithDelta(1.068, 0.001);
        });

        it('calculates purchaseProfitPercent (purchase_profit_percent)', function () {
            // (1.068 / 1.602) × 100 = 66.67
            $game = ['tf2_quantity' => 2, 'simulated_income' => 2.67, 'individual_cost' => null, 'sold_price' => 0];

            $result = $this->service->calculateFormulas($game, somatorioIncomes: 5.00, isEdit: false);

            expect($result['purchase_profit_percent'])->toEqualWithDelta(66.67, 0.01);
        });

        it('calculates saleProfit (sale_profit)', function () {
            // 5.00 - 1.60 = 3.40
            $game = ['tf2_quantity' => 0, 'simulated_income' => 0.0, 'individual_cost' => 1.60, 'sold_price' => 5.00];

            $result = $this->service->calculateFormulas($game, somatorioIncomes: 1.0, isEdit: true);

            expect($result['sale_profit'])->toEqualWithDelta(3.40, 0.001);
        });

        it('calculates saleProfitPercent (sale_profit_percent)', function () {
            // (3.40 / 1.60) × 100 = 212.5
            $game = ['tf2_quantity' => 0, 'simulated_income' => 0.0, 'individual_cost' => 1.60, 'sold_price' => 5.00];

            $result = $this->service->calculateFormulas($game, somatorioIncomes: 1.0, isEdit: true);

            expect($result['sale_profit_percent'])->toEqualWithDelta(212.5, 0.001);
        });

        it('skips cost recalculation when isEdit is true', function () {
            $game = ['tf2_quantity' => 0, 'simulated_income' => 0.0, 'individual_cost' => 3.00, 'sold_price' => 0];

            $result = $this->service->calculateFormulas($game, somatorioIncomes: 1.0, isEdit: true);

            expect($result['individual_cost'])->toBe(3.00);
        });

        it('returns null for both sale fields when the key has not been sold', function () {
            // sold_price null no banco = não vendida (diferente de lucro zero)
            $game = ['tf2_quantity' => 0, 'simulated_income' => 0.0, 'individual_cost' => 1.60, 'sold_price' => null];

            $result = $this->service->calculateFormulas($game, somatorioIncomes: 1.0, isEdit: true);

            expect($result['sale_profit'])->toBeNull()
                ->and($result['sale_profit_percent'])->toBeNull();
        });
    });

    // -------------------------------------------------------------------------

    describe('calculateSaleFormulas()', function () {

        it('returns sale_profit and sale_profit_percent as floats', function () {
            // 5.00 - 1.60 = 3.40 ; (3.40 / 1.60) × 100 = 212.5
            $result = $this->service->calculateSaleFormulas(5.00, 1.60);

            expect($result['sale_profit'])->toEqualWithDelta(3.40, 0.001)
                ->and($result['sale_profit_percent'])->toEqualWithDelta(212.5, 0.001);
        });

        it('returns a loss when sold for zero (cost is not recovered)', function () {
            // Vendido por €0 com custo €1.60 = perda de €1.60 (−100%)
            // Nota: "não vendida" é representada por null no fluxo principal,
            // não por salePrice=0. calculateSaleFormulas só é chamado quando
            // uma venda real ocorre (updateSoldOffers).
            $result = $this->service->calculateSaleFormulas(0.0, 1.60);

            expect($result['sale_profit'])->toEqualWithDelta(-1.60, 0.001)
                ->and($result['sale_profit_percent'])->toEqualWithDelta(-100.0, 0.001);
        });

        it('can return negative sale_profit when cost exceeds sale price', function () {
            // 1.00 - 3.00 = -2.00
            $result = $this->service->calculateSaleFormulas(1.00, 3.00);

            expect($result['sale_profit'])->toEqualWithDelta(-2.00, 0.001);
        });
    });
});
