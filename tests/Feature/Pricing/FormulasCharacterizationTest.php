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
|   TF2 preco_euro        = 1.500  (€ 1.50 per TF2 key)
|
*/

use App\Services\Keys\KeyCalculationService;
use Illuminate\Support\Facades\DB;

describe('KeyCalculationService', function () {

    beforeEach(function () {
        DB::table('taxas')->insert([
            ['name' => 'gamivoPercentualMenor', 'preco' => 0.060, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'gamivoFixoMenor',       'preco' => 0.250, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'gamivoPercentualMaior', 'preco' => 0.080, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'gamivoFixoMaior',       'preco' => 0.400, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('recursos')->insert([
            ['name' => 'TF2', 'preco_euro' => 1.500, 'preco_dolar' => 1.600, 'preco_real' => 8.000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->service = new KeyCalculationService();
    });

    // -------------------------------------------------------------------------

    describe('calculateFirstFormulas() — income simulado Gamivo', function () {

        it('subtracts only the fixed fee when price is below €0.28', function () {
            // 0.20 - 0.11 = 0.09
            $result = $this->service->calculateFirstFormulas([['precoCliente' => 0.20]]);

            expect($result['games'][0]['incomeSimulado'])->toEqualWithDelta(0.09, 0.001);
        });

        it('applies percentage + fixed fee for the lower tier (€0.28–€8)', function () {
            // 3.00 × (1 - 0.060) - 0.25 = 2.57
            $result = $this->service->calculateFirstFormulas([['precoCliente' => 3.00]]);

            expect($result['games'][0]['incomeSimulado'])->toEqualWithDelta(2.57, 0.001);
        });

        it('applies a higher percentage + fixed fee for the upper tier (≥ €8)', function () {
            // 10.00 × (1 - 0.080) - 0.40 = 8.80
            $result = $this->service->calculateFirstFormulas([['precoCliente' => 10.00]]);

            expect($result['games'][0]['incomeSimulado'])->toEqualWithDelta(8.80, 0.001);
        });

        it('falls into the lower tier at exactly the €0.28 boundary', function () {
            // 0.28 × (1 - 0.060) - 0.25 = 0.0132
            $result = $this->service->calculateFirstFormulas([['precoCliente' => 0.28]]);

            expect($result['games'][0]['incomeSimulado'])->toEqualWithDelta(0.0132, 0.0001);
        });

        it('falls into the upper tier at exactly the €8 boundary', function () {
            // 8.00 × (1 - 0.080) - 0.40 = 6.96
            $result = $this->service->calculateFirstFormulas([['precoCliente' => 8.00]]);

            expect($result['games'][0]['incomeSimulado'])->toEqualWithDelta(6.96, 0.001);
        });

        it('accumulates the sum of incomes across the batch', function () {
            // 2.57 + 8.80 = 11.37
            $result = $this->service->calculateFirstFormulas([
                ['precoCliente' => 3.00],
                ['precoCliente' => 10.00],
            ]);

            expect($result['somatorioIncomes'])->toEqualWithDelta(11.37, 0.001);
        });
    });

    // -------------------------------------------------------------------------

    describe('calculateFormulas() — individual cost and profit', function () {

        it('calculates individualCost proportional to the simulated income', function () {
            // 2 × 1.50 / 5.00 × 2.67 = 1.602
            $game = ['qtdTF2' => 2, 'incomeSimulado' => 2.67, 'valorPagoIndividual' => null, 'valorVendido' => 0];

            $result = $this->service->calculateFormulas($game, somatorioIncomes: 5.00, isEdit: false);

            expect($result['valorPagoIndividual'])->toEqualWithDelta(1.602, 0.001);
        });

        it('calculates purchaseProfit (lucroRS)', function () {
            // 2.67 - 1.602 = 1.068
            $game = ['qtdTF2' => 2, 'incomeSimulado' => 2.67, 'valorPagoIndividual' => null, 'valorVendido' => 0];

            $result = $this->service->calculateFormulas($game, somatorioIncomes: 5.00, isEdit: false);

            expect($result['lucroRS'])->toEqualWithDelta(1.068, 0.001);
        });

        it('calculates purchaseProfitPercent (lucroPercentual)', function () {
            // (1.068 / 1.602) × 100 = 66.67
            $game = ['qtdTF2' => 2, 'incomeSimulado' => 2.67, 'valorPagoIndividual' => null, 'valorVendido' => 0];

            $result = $this->service->calculateFormulas($game, somatorioIncomes: 5.00, isEdit: false);

            expect($result['lucroPercentual'])->toEqualWithDelta(66.67, 0.01);
        });

        it('calculates saleProfit (lucroVendaRS)', function () {
            // 5.00 - 1.60 = 3.40
            $game = ['qtdTF2' => 0, 'incomeSimulado' => 0.0, 'valorPagoIndividual' => 1.60, 'valorVendido' => 5.00];

            $result = $this->service->calculateFormulas($game, somatorioIncomes: 1.0, isEdit: true);

            expect($result['lucroVendaRS'])->toEqualWithDelta(3.40, 0.001);
        });

        it('calculates saleProfitPercent (lucroVendaPercentual)', function () {
            // (3.40 / 1.60) × 100 = 212.5
            $game = ['qtdTF2' => 0, 'incomeSimulado' => 0.0, 'valorPagoIndividual' => 1.60, 'valorVendido' => 5.00];

            $result = $this->service->calculateFormulas($game, somatorioIncomes: 1.0, isEdit: true);

            expect($result['lucroVendaPercentual'])->toEqualWithDelta(212.5, 0.001);
        });

        it('skips cost recalculation when isEdit is true', function () {
            $game = ['qtdTF2' => 0, 'incomeSimulado' => 0.0, 'valorPagoIndividual' => 3.00, 'valorVendido' => 0];

            $result = $this->service->calculateFormulas($game, somatorioIncomes: 1.0, isEdit: true);

            expect($result['valorPagoIndividual'])->toBe(3.00);
        });

        it('returns null for both sale fields when the key has not been sold', function () {
            // valorVendido null no banco = não vendida (diferente de lucro zero)
            $game = ['qtdTF2' => 0, 'incomeSimulado' => 0.0, 'valorPagoIndividual' => 1.60, 'valorVendido' => null];

            $result = $this->service->calculateFormulas($game, somatorioIncomes: 1.0, isEdit: true);

            expect($result['lucroVendaRS'])->toBeNull()
                ->and($result['lucroVendaPercentual'])->toBeNull();
        });
    });

    // -------------------------------------------------------------------------

    describe('calculateSaleFormulas()', function () {

        it('returns lucroVendaRS and lucroVendaPercentual as floats', function () {
            // 5.00 - 1.60 = 3.40 ; (3.40 / 1.60) × 100 = 212.5
            $result = $this->service->calculateSaleFormulas(5.00, 1.60);

            expect($result['lucroVendaRS'])->toEqualWithDelta(3.40, 0.001)
                ->and($result['lucroVendaPercentual'])->toEqualWithDelta(212.5, 0.001);
        });

        it('returns a loss when sold for zero (cost is not recovered)', function () {
            // Vendido por €0 com custo €1.60 = perda de €1.60 (−100%)
            // Nota: "não vendida" é representada por null no fluxo principal,
            // não por salePrice=0. calculateSaleFormulas só é chamado quando
            // uma venda real ocorre (updateSoldOffers).
            $result = $this->service->calculateSaleFormulas(0.0, 1.60);

            expect($result['lucroVendaRS'])->toEqualWithDelta(-1.60, 0.001)
                ->and($result['lucroVendaPercentual'])->toEqualWithDelta(-100.0, 0.001);
        });

        it('can return negative lucroVendaRS when cost exceeds sale price', function () {
            // 1.00 - 3.00 = -2.00
            $result = $this->service->calculateSaleFormulas(1.00, 3.00);

            expect($result['lucroVendaRS'])->toEqualWithDelta(-2.00, 0.001);
        });
    });
});
