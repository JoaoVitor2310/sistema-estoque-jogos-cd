<?php

/*
|--------------------------------------------------------------------------
| PartnerSplit — divisão entre os dois sócios
|--------------------------------------------------------------------------
|
| PHP puro — sem DB, sem bootstrap do framework.
|
| O que importa aqui é a reconciliação: sócio 1 + sócio 2 tem que bater com o
| total distribuído em qualquer divisão, e o centavo órfão fica com o Sócio 1.
| Herdado da cascata removida (docs/adr/0005).
|
*/

use App\Domain\Financial\PartnerSplit;

describe('PartnerSplit::divide', function () {

    it('splits evenly at 50/50', function () {
        $split = PartnerSplit::divide(782.26, 0.50);

        expect($split->partnerOne)->toBe(391.13)
            ->and($split->partnerTwo)->toBe(391.13);
    });

    it('gives the orphan cent to partner one on an odd split', function () {
        $split = PartnerSplit::divide(0.01, 0.50);

        // 0,5 centavo arredonda half-up para 1 centavo no Sócio 1; o Sócio 2 leva o resto.
        expect($split->partnerOne)->toBe(0.01)
            ->and($split->partnerTwo)->toBe(0.0);
    });

    it('reconciles exactly on a configurable share', function () {
        $split = PartnerSplit::divide(1000.00, 0.70);

        expect($split->partnerOne)->toBe(700.00)
            ->and($split->partnerTwo)->toBe(300.00);
    });

    it('always reconciles with the distributed total', function () {
        foreach ([[1188.00, 0.50], [999.99, 0.33], [0.03, 0.50], [7.77, 0.6666]] as [$amount, $share]) {
            $split = PartnerSplit::divide($amount, $share);

            expect(round($split->partnerOne + $split->partnerTwo, 2))->toBe($amount);
        }
    });

    it('rejects a non-positive amount', function () {
        expect(fn () => PartnerSplit::divide(0, 0.50))->toThrow(InvalidArgumentException::class);
        expect(fn () => PartnerSplit::divide(-10, 0.50))->toThrow(InvalidArgumentException::class);
    });

    it('rejects a share outside 0..1', function () {
        expect(fn () => PartnerSplit::divide(100, 1.5))->toThrow(InvalidArgumentException::class);
        expect(fn () => PartnerSplit::divide(100, -0.1))->toThrow(InvalidArgumentException::class);
    });
});
