<?php

/*
|--------------------------------------------------------------------------
| OrderPayoutSplitter — unit tests
|--------------------------------------------------------------------------
|
| PHP puro — sem DB, sem bootstrap do framework.
|
| Duas regras verificadas aqui:
|   - a taxa de mediação (€0,01) é descontada uma vez por pedido, não por key
|   - a soma dos valores rateados é exatamente o líquido do pedido (maior resto)
|
*/

use App\Domain\Pricing\IncomeCalculator;
use App\Domain\Pricing\OrderPayoutSplitter;

describe('OrderPayoutSplitter::split()', function () {

    it('returns an empty array when the order has no keys', function () {
        expect(OrderPayoutSplitter::split([]))->toBe([]);
    });

    it('subtracts the mediation fee once on a single-key order', function () {
        expect(OrderPayoutSplitter::split(['KEY-A' => 2.36]))
            ->toBe(['KEY-A' => 2.35]);
    });

    it('subtracts the mediation fee once regardless of how many keys the order has', function () {
        // Pedido real: 3 ofertas de Nekopara, bruto 2.36 + 1.45 + 0.82 = 4.63
        $result = OrderPayoutSplitter::split([
            'NEKO-1' => 2.36,
            'NEKO-2' => 1.45,
            'NEKO-0' => 0.82,
        ]);

        expect(array_sum($result))->toEqualWithDelta(4.62, 0.001);
    });

    it('keeps each offer with its own value instead of averaging the order', function () {
        $result = OrderPayoutSplitter::split([
            'NEKO-1' => 2.36,
            'NEKO-2' => 1.45,
            'NEKO-0' => 0.82,
        ]);

        // O centavo da mediação sai da maior fração — as demais ficam intactas
        expect($result['NEKO-1'])->toEqualWithDelta(2.35, 0.001)
            ->and($result['NEKO-2'])->toEqualWithDelta(1.45, 0.001)
            ->and($result['NEKO-0'])->toEqualWithDelta(0.82, 0.001);
    });

    it('distributes leftover cents so the sum matches the order exactly', function () {
        // 1.01 − 0.01 = 1.00 para 3 keys iguais: 0.34 / 0.33 / 0.33, nunca 0.99
        $result = OrderPayoutSplitter::split([
            'KEY-A' => 1.01 / 3,
            'KEY-B' => 1.01 / 3,
            'KEY-C' => 1.01 / 3,
        ]);

        expect(array_sum($result))->toEqualWithDelta(1.00, 0.001)
            ->and(array_values($result))->toEqualCanonicalizing([0.34, 0.33, 0.33]);
    });

    it('rounds every allocation to whole cents', function () {
        $result = OrderPayoutSplitter::split([
            'KEY-A' => 3.3333,
            'KEY-B' => 1.1111,
        ]);

        foreach ($result as $value) {
            expect(round($value, 2))->toBe($value);
        }
    });

    it('splits equally when the order registered no gross value', function () {
        // Sem peso positivo não há proporção a seguir; o líquido negativo da
        // mediação é dividido igualmente em vez de concentrar numa key só
        $result = OrderPayoutSplitter::split(['KEY-A' => 0.0, 'KEY-B' => 0.0]);

        expect(array_sum($result))->toEqualWithDelta(-IncomeCalculator::MEDIATION_FEE, 0.001);
    });
});
