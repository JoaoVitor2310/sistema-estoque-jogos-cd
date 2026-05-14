<?php

/*
|--------------------------------------------------------------------------
| SalePriceCalculator — unit tests
|--------------------------------------------------------------------------
|
| PHP puro — sem DB, sem bootstrap do framework.
| Testa a regra de negócio: rótulo padronizado do custo da trade.
|
*/

use App\Domain\Pricing\SalePriceCalculator;

describe('SalePriceCalculator', function () {

    // -----------------------------------------------------------------------
    // tradeCostLabel
    // -----------------------------------------------------------------------

    describe('tradeCostLabel', function () {

        it('generates label in the expected format', function () {
            // "2x TF2 Keys / 5" — 2 TF2 keys pagaram por 5 jogos do lote
            expect(SalePriceCalculator::tradeCostLabel(2.0, 5))->toBe('2x TF2 Keys / 5');
        });

        it('generates label for a single-key batch', function () {
            expect(SalePriceCalculator::tradeCostLabel(1.0, 1))->toBe('1x TF2 Keys / 1');
        });

        it('generates label for a large batch', function () {
            expect(SalePriceCalculator::tradeCostLabel(5.0, 20))->toBe('5x TF2 Keys / 20');
        });

        it('preserves fractional qtdTF2 when the trade was split across many games', function () {
            // Ex: 2.5 TF2 keys para 3 jogos
            expect(SalePriceCalculator::tradeCostLabel(2.5, 3))->toBe('2.5x TF2 Keys / 3');
        });
    });
});
