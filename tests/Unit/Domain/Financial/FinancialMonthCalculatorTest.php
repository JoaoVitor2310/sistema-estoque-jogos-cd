<?php

/*
|--------------------------------------------------------------------------
| FinancialMonthCalculator — unit tests
|--------------------------------------------------------------------------
|
| PHP puro — sem DB, sem bootstrap do framework.
| Cobre a cascata do fechamento mensal com os números reais dos meses de
| referência, além das regras de arredondamento (half-up por passo), do
| centavo órfão (Sócio 1) e da reconciliação exata (sócio1 + sócio2 = distribuível).
|
*/

use App\Domain\Financial\FinancialMonthCalculator;

describe('FinancialMonthCalculator::calculate', function () {

    it('reproduces the real financial month (revenue 3,257.03 → 391.13 each)', function () {
        // Reserva 231 × 8,50 = 1.963,50 · saídas 87,05 + 120 = 207,05
        $result = FinancialMonthCalculator::calculate(
            totalIncome: 3257.03,
            tf2TargetQuantity: 231,
            tf2Price: 8.50,
            totalExpenses: 207.05,
            reinvestmentPercent: 0.20,
            emergencyPercent: 0.10,
            partnerOneShare: 0.50,
        );

        expect($result->tf2Reserve)->toEqualWithDelta(1963.50, 0.001)
            ->and($result->base)->toEqualWithDelta(1086.48, 0.001)
            ->and($result->reinvestment)->toEqualWithDelta(217.30, 0.001)
            ->and($result->afterReinvestment)->toEqualWithDelta(869.18, 0.001)
            ->and($result->emergency)->toEqualWithDelta(86.92, 0.001)
            ->and($result->distributable)->toEqualWithDelta(782.26, 0.001)
            ->and($result->partnerOne)->toEqualWithDelta(391.13, 0.001)
            ->and($result->partnerTwo)->toEqualWithDelta(391.13, 0.001);
    });

    it('reproduces the spec example (revenue 8,500.00 → 2,195.62 each)', function () {
        // Reserva 240 × 8,50 = 2.040 · saídas 87,05 + 120 + 154 = 361,05
        $result = FinancialMonthCalculator::calculate(
            totalIncome: 8500.00,
            tf2TargetQuantity: 240,
            tf2Price: 8.50,
            totalExpenses: 361.05,
            reinvestmentPercent: 0.20,
            emergencyPercent: 0.10,
            partnerOneShare: 0.50,
        );

        expect($result->tf2Reserve)->toEqualWithDelta(2040.00, 0.001)
            ->and($result->base)->toEqualWithDelta(6098.95, 0.001)
            ->and($result->reinvestment)->toEqualWithDelta(1219.79, 0.001)
            ->and($result->afterReinvestment)->toEqualWithDelta(4879.16, 0.001)
            ->and($result->emergency)->toEqualWithDelta(487.92, 0.001)
            ->and($result->distributable)->toEqualWithDelta(4391.24, 0.001)
            ->and($result->partnerOne)->toEqualWithDelta(2195.62, 0.001)
            ->and($result->partnerTwo)->toEqualWithDelta(2195.62, 0.001);
    });

    it('computes the TF2 reserve as target quantity × price', function () {
        $result = FinancialMonthCalculator::calculate(
            totalIncome: 10000.00,
            tf2TargetQuantity: 210,
            tf2Price: 10.50,
            totalExpenses: 0.0,
            reinvestmentPercent: 0.20,
            emergencyPercent: 0.10,
            partnerOneShare: 0.50,
        );

        // 210 × 10,50 = 2.205,00
        expect($result->tf2Reserve)->toEqualWithDelta(2205.00, 0.001);
    });

    it('takes reinvestment as a percentage of the base (post reserve + expenses)', function () {
        // base = 1000 − 0 − 0 = 1000 · 20% = 200
        $result = FinancialMonthCalculator::calculate(
            totalIncome: 1000.00,
            tf2TargetQuantity: 0,
            tf2Price: 0.0,
            totalExpenses: 0.0,
            reinvestmentPercent: 0.20,
            emergencyPercent: 0.10,
            partnerOneShare: 0.50,
        );

        expect($result->base)->toEqualWithDelta(1000.00, 0.001)
            ->and($result->reinvestment)->toEqualWithDelta(200.00, 0.001);
    });

    it('takes emergency as a percentage of the balance AFTER reinvestment (compounding order)', function () {
        // base 1000 → reinvest 20% = 200 → resta 800 → emergência 10% de 800 = 80 (não 100)
        $result = FinancialMonthCalculator::calculate(
            totalIncome: 1000.00,
            tf2TargetQuantity: 0,
            tf2Price: 0.0,
            totalExpenses: 0.0,
            reinvestmentPercent: 0.20,
            emergencyPercent: 0.10,
            partnerOneShare: 0.50,
        );

        expect($result->afterReinvestment)->toEqualWithDelta(800.00, 0.001)
            ->and($result->emergency)->toEqualWithDelta(80.00, 0.001)
            ->and($result->distributable)->toEqualWithDelta(720.00, 0.001);
    });

    it('gives the orphan cent to Partner 1 on an odd split', function () {
        // distribuível = 100,01 (sem reserva, sem saídas, sem %) → 50,01 / 50,00
        $result = FinancialMonthCalculator::calculate(
            totalIncome: 100.01,
            tf2TargetQuantity: 0,
            tf2Price: 0.0,
            totalExpenses: 0.0,
            reinvestmentPercent: 0.0,
            emergencyPercent: 0.0,
            partnerOneShare: 0.50,
        );

        expect($result->distributable)->toEqualWithDelta(100.01, 0.001)
            ->and($result->partnerOne)->toEqualWithDelta(50.01, 0.001)
            ->and($result->partnerTwo)->toEqualWithDelta(50.00, 0.001);
    });

    it('always reconciles: partnerOne + partnerTwo equals distributable exactly', function () {
        $result = FinancialMonthCalculator::calculate(
            totalIncome: 3333.33,
            tf2TargetQuantity: 111,
            tf2Price: 7.77,
            totalExpenses: 55.55,
            reinvestmentPercent: 0.20,
            emergencyPercent: 0.10,
            partnerOneShare: 0.50,
        );

        // Reconciliação exata em centavos — sem centavo sumindo no arredondamento
        expect(round($result->partnerOne + $result->partnerTwo, 2))
            ->toBe(round($result->distributable, 2));
    });

    it('respects configurable reinvestment/emergency percentages', function () {
        // base 1000 → reinvest 25% = 250 → resta 750 → emergência 15% de 750 = 112,50
        $result = FinancialMonthCalculator::calculate(
            totalIncome: 1000.00,
            tf2TargetQuantity: 0,
            tf2Price: 0.0,
            totalExpenses: 0.0,
            reinvestmentPercent: 0.25,
            emergencyPercent: 0.15,
            partnerOneShare: 0.50,
        );

        expect($result->reinvestment)->toEqualWithDelta(250.00, 0.001)
            ->and($result->afterReinvestment)->toEqualWithDelta(750.00, 0.001)
            ->and($result->emergency)->toEqualWithDelta(112.50, 0.001)
            ->and($result->distributable)->toEqualWithDelta(637.50, 0.001);
    });

    it('respects a configurable partner split (60/40)', function () {
        // distribuível 1000 → 60% = 600 · resto 400
        $result = FinancialMonthCalculator::calculate(
            totalIncome: 1000.00,
            tf2TargetQuantity: 0,
            tf2Price: 0.0,
            totalExpenses: 0.0,
            reinvestmentPercent: 0.0,
            emergencyPercent: 0.0,
            partnerOneShare: 0.60,
        );

        expect($result->partnerOne)->toEqualWithDelta(600.00, 0.001)
            ->and($result->partnerTwo)->toEqualWithDelta(400.00, 0.001);
    });

    it('applies half-up rounding at each step', function () {
        // base = 100 · reinvest 12,5% = 12,50 exato; usar valor que force .5 no centavo:
        // base 1000,10 → 20% = 200,02 (200.020) ; teste um .5: base 100,05 → 50% = 50,025 → 50,03 (half-up)
        $result = FinancialMonthCalculator::calculate(
            totalIncome: 100.05,
            tf2TargetQuantity: 0,
            tf2Price: 0.0,
            totalExpenses: 0.0,
            reinvestmentPercent: 0.50,
            emergencyPercent: 0.0,
            partnerOneShare: 0.50,
        );

        // 100,05 × 50% = 50,025 → half-up → 50,03
        expect($result->reinvestment)->toEqualWithDelta(50.03, 0.001);
    });

    it('returns all zeros for an empty month', function () {
        $result = FinancialMonthCalculator::calculate(
            totalIncome: 0.0,
            tf2TargetQuantity: 0,
            tf2Price: 0.0,
            totalExpenses: 0.0,
            reinvestmentPercent: 0.20,
            emergencyPercent: 0.10,
            partnerOneShare: 0.50,
        );

        expect($result->base)->toEqualWithDelta(0.0, 0.001)
            ->and($result->distributable)->toEqualWithDelta(0.0, 0.001)
            ->and($result->partnerOne)->toEqualWithDelta(0.0, 0.001)
            ->and($result->partnerTwo)->toEqualWithDelta(0.0, 0.001);
    });
});
