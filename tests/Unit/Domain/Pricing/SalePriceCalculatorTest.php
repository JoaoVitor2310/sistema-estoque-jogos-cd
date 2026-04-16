<?php

/*
|--------------------------------------------------------------------------
| SalePriceCalculator — unit tests
|--------------------------------------------------------------------------
|
| PHP puro — sem DB, sem bootstrap do framework.
| Testa as duas regras de negócio: preço mínimo de venda (5% de markup)
| e rótulo padronizado do custo da trade.
|
*/

use App\Domain\Pricing\SalePriceCalculator;

describe('SalePriceCalculator', function () {

    // -----------------------------------------------------------------------
    // minimumSalePrice
    // -----------------------------------------------------------------------

    describe('minimumSalePrice', function () {

        it('applies 5% markup over the market price', function () {
            expect(SalePriceCalculator::minimumSalePrice(10.00))->toEqualWithDelta(10.50, 0.001);
        });

        it('works with fractional prices common in Gamivo', function () {
            expect(SalePriceCalculator::minimumSalePrice(3.49))->toEqualWithDelta(3.6645, 0.001);
            expect(SalePriceCalculator::minimumSalePrice(0.50))->toEqualWithDelta(0.525, 0.001);
        });

        it('always returns a price higher than the client price', function () {
            $clientPrice = 7.00;
            expect(SalePriceCalculator::minimumSalePrice($clientPrice))->toBeGreaterThan($clientPrice);
        });

        it('keeps precision on very low prices', function () {
            // Keys de centavos ainda devem ter margem positiva
            expect(SalePriceCalculator::minimumSalePrice(0.10))->toEqualWithDelta(0.105, 0.0001);
        });
    });

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
