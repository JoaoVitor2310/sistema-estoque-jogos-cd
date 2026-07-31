<?php

/*
|--------------------------------------------------------------------------
| Money — unit tests
|--------------------------------------------------------------------------
|
| PHP puro. Cobre o primitivo monetário: conversão reais↔centavos com
| arredondamento correto, soma/subtração e percentual half-up.
|
*/

use App\Domain\Financial\Money;

describe('Money', function () {

    it('converts reais to cents and back', function () {
        expect(Money::fromReais(1963.50)->cents)->toBe(196350)
            ->and(Money::fromReais(1963.50)->toReais())->toEqualWithDelta(1963.50, 0.001);
    });

    it('rounds float imprecision when building from reais', function () {
        // 361.05 * 100 pode virar 36104.9999… em float; deve fechar em 36105
        expect(Money::fromReais(361.05)->cents)->toBe(36105);
    });

    it('adds and subtracts in exact cents', function () {
        $result = Money::fromReais(1086.48)->minus(Money::fromReais(217.30));

        expect($result->cents)->toBe(86918)
            ->and($result->toReais())->toEqualWithDelta(869.18, 0.001);
    });

    it('takes a percentage with half-up rounding', function () {
        // 5002.5 centavos → half-up → 5003 (não 5002)
        expect(Money::fromReais(100.05)->percentage(0.50)->cents)->toBe(5003);
    });

    it('rounds a percentage down below the half boundary', function () {
        // 869.18 × 10% = 8691.8 centavos → 8692
        expect(Money::fromReais(869.18)->percentage(0.10)->cents)->toBe(8692);
    });

    it('exposes a zero value', function () {
        expect(Money::zero()->cents)->toBe(0);
    });
});
