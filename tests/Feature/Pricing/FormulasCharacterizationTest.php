<?php

/*
|--------------------------------------------------------------------------
| Formulas — characterization tests
|--------------------------------------------------------------------------
|
| Documents the CURRENT behaviour of App\Http\Helpers\Formulas.
| When Formulas.php is deleted in Phase 1, the same tests must pass
| against the new Domain/Pricing/ classes with identical numeric results.
|
| Marketplace in scope: Gamivo only (G2A and Kinguin will be removed).
|
| Seeded rates (mirror production values):
|   gamivoPercentualMenor = 0.060  (6.0 %)
|   gamivoFixoMenor       = 0.250  (€ 0.25)
|   gamivoPercentualMaior = 0.080  (8.0 %)
|   gamivoFixoMaior       = 0.400  (€ 0.40)
|   TF2 preco_euro        = 1.500  (€ 1.50 per TF2 key)
|
*/

use App\Http\Helpers\Formulas;
use Illuminate\Support\Facades\DB;

describe('Formulas', function () {

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

        $this->formulas = new Formulas();
    });

    // -------------------------------------------------------------------------

    describe('calcIncomeSimulado() — Gamivo', function () {

        it('subtracts only the fixed fee when price is below €0.28', function () {
            // 0.20 - 0.11 = 0.09
            expect($this->formulas->calcIncomeSimulado(
                idFormato: 1,
                idPlataforma: 3,
                precoCliente: 0.20,
                precoVenda: 0.20
            ))->toBe('0.09');
        });

        it('applies percentage + fixed fee for the lower tier (€0.28–€8)', function () {
            // 3.00 × (1 - 0.060) - 0.25 = 2.82 - 0.25 = 2.57
            expect($this->formulas->calcIncomeSimulado(
                idFormato: 1,
                idPlataforma: 3,
                precoCliente: 3.00,
                precoVenda: 3.00
            ))->toBe('2.57');
        });

        it('applies a higher percentage + fixed fee for the upper tier (≥ €8)', function () {
            // 10.00 × (1 - 0.080) - 0.40 = 9.2 - 0.40 = 8.80
            expect($this->formulas->calcIncomeSimulado(
                idFormato: 1,
                idPlataforma: 3,
                precoCliente: 10.00,
                precoVenda: 10.00
            ))->toBe('8.80');
        });

        it('falls into the lower tier at exactly the €0.28 boundary', function () {
            // 0.28 × (1 - 0.060) - 0.25 = 0.2632 - 0.25 = 0.0132 → "0.01"
            expect($this->formulas->calcIncomeSimulado(
                idFormato: 1,
                idPlataforma: 3,
                precoCliente: 0.28,
                precoVenda: 0.28
            ))->toBe('0.01');
        });

        it('falls into the upper tier at exactly the €8 boundary', function () {
            // 8.00 × (1 - 0.080) - 0.40 = 7.36 - 0.40 = 6.96
            expect($this->formulas->calcIncomeSimulado(
                idFormato: 1,
                idPlataforma: 3,
                precoCliente: 8.00,
                precoVenda: 8.00
            ))->toBe('6.96');
        });
    });

    // -------------------------------------------------------------------------

    describe('calcValorPagoIndividual()', function () {

        it('is proportional to the simulated income', function () {
            // 2 × 1.50 / 5.00 × 2.67 = 0.60 × 2.67 = 1.602 → "1.60"
            expect($this->formulas->calcValorPagoIndividual(
                qtdTF2: 2,
                somatorioIncomes: 5.00,
                primeiroIncome: 2.67
            ))->toBe('1.60');
        });

        it('returns zero when the total income sum is zero', function () {
            expect($this->formulas->calcValorPagoIndividual(2, 0, 2.67))->toBe(0);
        });

        it('returns zero when the first income is zero', function () {
            expect($this->formulas->calcValorPagoIndividual(2, 5.00, 0))->toBe(0);
        });

        it('has a 0.01 floor to prevent zero or negative results', function () {
            // qtdTF2 = 0 → result = 0 → floor → "0.01"
            expect($this->formulas->calcValorPagoIndividual(
                qtdTF2: 0,
                somatorioIncomes: 5.00,
                primeiroIncome: 2.67
            ))->toBe('0.01');
        });
    });

    // -------------------------------------------------------------------------

    describe('calcLucroReal()', function () {

        it('is income minus individual cost', function () {
            // 2.67 - 1.60 = 1.07
            expect($this->formulas->calcLucroReal('2.67', '1.60'))->toBe('1.07');
        });

        it('returns zero when income is empty', function () {
            expect($this->formulas->calcLucroReal('', 1.60))->toBe(0);
        });

        it('can be negative when purchase cost exceeds income', function () {
            // 1.00 - 3.00 = -2.00
            expect($this->formulas->calcLucroReal('1.00', '3.00'))->toBe('-2.00');
        });
    });

    // -------------------------------------------------------------------------

    describe('calcLucroPercentual()', function () {

        it('is calculated over individual cost', function () {
            // (1.07 / 1.60) × 100 = 66.875 → "66.88"
            expect($this->formulas->calcLucroPercentual('1.07', '1.60'))->toBe('66.88');
        });

        it('returns zero when individual cost is zero', function () {
            expect($this->formulas->calcLucroPercentual('1.07', 0))->toBe(0);
        });

        it('returns zero when profit is empty', function () {
            expect($this->formulas->calcLucroPercentual('', '1.60'))->toBe(0);
        });
    });

    // -------------------------------------------------------------------------

    describe('calcLucroVendaReal()', function () {

        it('is sale price minus individual cost', function () {
            // 5.00 - 1.60 = 3.40
            expect($this->formulas->calcLucroVendaReal('5.00', '1.60'))->toBe('3.40');
        });

        it('returns zero when the key has not been sold yet', function () {
            expect($this->formulas->calcLucroVendaReal('', '1.60'))->toBe(0);
        });
    });

    // -------------------------------------------------------------------------

    describe('calcLucroVendaPercentual()', function () {

        it('is calculated over individual cost', function () {
            // (3.40 / 1.60) × 100 = 212.5 → "212.50"
            expect($this->formulas->calcLucroVendaPercentual('3.40', '1.60'))->toBe('212.50');
        });

        it('returns zero when individual cost is zero', function () {
            expect($this->formulas->calcLucroVendaPercentual('3.40', 0))->toBe(0);
        });

        it('returns zero when sale profit is empty', function () {
            expect($this->formulas->calcLucroVendaPercentual('', '1.60'))->toBe(0);
        });
    });
});
