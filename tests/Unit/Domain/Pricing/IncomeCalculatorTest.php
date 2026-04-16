<?php

/*
|--------------------------------------------------------------------------
| IncomeCalculator — unit tests
|--------------------------------------------------------------------------
|
| PHP puro — sem DB, sem bootstrap do framework.
| MarketplaceFee é construído diretamente com valores espelhados da produção:
|   percentualMenor = 0.060, fixoMenor = 0.250
|   percentualMaior = 0.080, fixoMaior = 0.400
|
*/

use App\Domain\Pricing\IncomeCalculator;
use App\Domain\Pricing\ValueObjects\MarketplaceFee;

describe('IncomeCalculator', function () {

    beforeEach(function () {
        $this->fee = new MarketplaceFee(
            percentualMenor: 0.060,
            fixoMenor:       0.250,
            percentualMaior: 0.080,
            fixoMaior:       0.400,
        );
    });

    describe('forGamivo()', function () {

        it('subtracts only the micro fixed fee (€0.11) when price is below €0.28', function () {
            // 0.20 − 0.11 = 0.09
            expect(IncomeCalculator::forGamivo(0.20, $this->fee))
                ->toEqualWithDelta(0.09, 0.001);
        });

        it('applies percentage + fixed fee for the lower tier (€0.28–€8)', function () {
            // 3.00 × (1 − 0.060) − 0.25 = 2.57
            expect(IncomeCalculator::forGamivo(3.00, $this->fee))
                ->toEqualWithDelta(2.57, 0.001);
        });

        it('applies a higher percentage + fixed fee for the upper tier (≥ €8)', function () {
            // 10.00 × (1 − 0.080) − 0.40 = 8.80
            expect(IncomeCalculator::forGamivo(10.00, $this->fee))
                ->toEqualWithDelta(8.80, 0.001);
        });

        it('falls into the lower tier at exactly the €0.28 boundary', function () {
            // 0.28 × (1 − 0.060) − 0.25 = 0.0132
            expect(IncomeCalculator::forGamivo(0.28, $this->fee))
                ->toEqualWithDelta(0.0132, 0.0001);
        });

        it('falls into the upper tier at exactly the €8 boundary', function () {
            // 8.00 × (1 − 0.080) − 0.40 = 6.96
            expect(IncomeCalculator::forGamivo(8.00, $this->fee))
                ->toEqualWithDelta(6.96, 0.001);
        });

        it('does not use fixoMenor for the micro tier — micro has its own fixed fee of €0.11', function () {
            // Mesmo que fixoMenor seja 0.25, abaixo de 0.28 a taxa é 0.11
            $feeWithDifferentFixo = new MarketplaceFee(
                percentualMenor: 0.060,
                fixoMenor:       0.999, // valor diferente para confirmar que não é usado
                percentualMaior: 0.080,
                fixoMaior:       0.400,
            );

            // 0.20 − 0.11 = 0.09 (fixoMenor=0.999 ignorado)
            expect(IncomeCalculator::forGamivo(0.20, $feeWithDifferentFixo))
                ->toEqualWithDelta(0.09, 0.001);
        });

        it('can return a negative income when clientPrice is very low', function () {
            // 0.05 − 0.11 = −0.06
            expect(IncomeCalculator::forGamivo(0.05, $this->fee))
                ->toEqualWithDelta(-0.06, 0.001);
        });
    });
});
