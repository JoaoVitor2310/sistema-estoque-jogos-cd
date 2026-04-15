<?php

/*
|--------------------------------------------------------------------------
| ProfitCalculator — unit tests
|--------------------------------------------------------------------------
|
| Pure PHP — no DB, no framework bootstrap.
| All methods return floats (or null for unsold keys).
|
*/

use App\Domain\Pricing\ProfitCalculator;

describe('ProfitCalculator', function () {

    // ── individualCost ────────────────────────────────────────────────────────

    describe('individualCost()', function () {
        it('is proportional to the simulated income', function () {
            // 2 × 1.50 / 5.00 × 2.67 = 1.602
            $result = ProfitCalculator::individualCost(
                qtdTF2:           2.0,
                tf2EuroPrice:     1.50,
                somatorioIncomes: 5.00,
                gameIncome:       2.67,
            );

            expect($result)->toEqualWithDelta(1.602, 0.001);
        });

        it('returns 0.0 when somatorioIncomes is zero', function () {
            expect(ProfitCalculator::individualCost(2.0, 1.50, 0.0, 2.67))->toBe(0.0);
        });

        it('returns 0.0 when gameIncome is zero', function () {
            expect(ProfitCalculator::individualCost(2.0, 1.50, 5.00, 0.0))->toBe(0.0);
        });

        it('applies a 0.01 floor when qtdTF2 is zero', function () {
            // 0 × 1.50 / 5.00 × 2.67 = 0 → floor → 0.01
            $result = ProfitCalculator::individualCost(0.0, 1.50, 5.00, 2.67);

            expect($result)->toEqualWithDelta(0.01, 0.0001);
        });
    });

    // ── purchaseProfit ────────────────────────────────────────────────────────

    describe('purchaseProfit()', function () {
        it('is income minus individual cost', function () {
            // 2.67 - 1.60 = 1.07
            expect(ProfitCalculator::purchaseProfit(2.67, 1.60))->toEqualWithDelta(1.07, 0.001);
        });

        it('returns 0.0 when incomeSimulado is zero', function () {
            expect(ProfitCalculator::purchaseProfit(0.0, 1.60))->toBe(0.0);
        });

        it('can be negative when cost exceeds income', function () {
            // 1.00 - 3.00 = -2.00
            expect(ProfitCalculator::purchaseProfit(1.00, 3.00))->toEqualWithDelta(-2.00, 0.001);
        });
    });

    // ── purchaseProfitPercent ─────────────────────────────────────────────────

    describe('purchaseProfitPercent()', function () {
        it('is calculated over individual cost', function () {
            // (1.07 / 1.60) × 100 = 66.875
            expect(ProfitCalculator::purchaseProfitPercent(1.07, 1.60))->toEqualWithDelta(66.875, 0.001);
        });

        it('returns 0.0 when profit is zero', function () {
            expect(ProfitCalculator::purchaseProfitPercent(0.0, 1.60))->toBe(0.0);
        });

        it('uses 0.01 as cost floor when individual cost is zero', function () {
            // custo 0 → usa 0.01: (1.07 / 0.01) × 100 = 10700
            expect(ProfitCalculator::purchaseProfitPercent(1.07, 0.0))->toEqualWithDelta(10700.0, 0.1);
        });
    });

    // ── saleProfit ────────────────────────────────────────────────────────────

    describe('saleProfit()', function () {
        it('is sale price minus individual cost', function () {
            // 5.00 - 1.60 = 3.40
            expect(ProfitCalculator::saleProfit(5.00, 1.60))->toEqualWithDelta(3.40, 0.001);
        });

        it('returns null when the key has not been sold yet', function () {
            // null no banco significa não vendida — diferente de lucro zero
            expect(ProfitCalculator::saleProfit(null, 1.60))->toBeNull();
        });

        it('returns the total loss when soldPrice is negative (refund with Gamivo penalty)', function () {
            // Venda: +1.50 | Reembolso ao cliente: -1.50 | Penalidade Gamivo: -1.00
            // soldPrice representa o líquido recebido da Gamivo = -1.00
            // saleProfit(-1.00, 1.50) = -1.00 - 1.50 = -2.50
            expect(ProfitCalculator::saleProfit(-1.00, 1.50))->toEqualWithDelta(-2.50, 0.001);
        });
    });

    // ── saleProfitPercent ─────────────────────────────────────────────────────

    describe('saleProfitPercent()', function () {
        it('is calculated over individual cost', function () {
            // (3.40 / 1.60) × 100 = 212.5
            expect(ProfitCalculator::saleProfitPercent(3.40, 1.60))->toEqualWithDelta(212.5, 0.001);
        });

        it('returns null when key has not been sold yet', function () {
            expect(ProfitCalculator::saleProfitPercent(null, 1.60))->toBeNull();
        });

        it('returns 0.0 when sale profit is zero', function () {
            expect(ProfitCalculator::saleProfitPercent(0.0, 1.60))->toBe(0.0);
        });

        it('uses 0.01 as cost floor when individual cost is zero', function () {
            // custo 0 → usa 0.01: (3.40 / 0.01) × 100 = 34000
            expect(ProfitCalculator::saleProfitPercent(3.40, 0.0))->toEqualWithDelta(34000.0, 0.1);
        });

        it('calculates the loss percentage for a refund with Gamivo penalty', function () {
            // saleProfit = -2.50 (reembolso + penalidade), custo = 1.50
            // (-2.50 / 1.50) × 100 = -166.67%
            expect(ProfitCalculator::saleProfitPercent(-2.50, 1.50))->toEqualWithDelta(-166.67, 0.01);
        });
    });
});
