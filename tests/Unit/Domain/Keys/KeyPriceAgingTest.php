<?php

/*
|--------------------------------------------------------------------------
| KeyPriceAging — unit tests
|--------------------------------------------------------------------------
|
| PHP puro — sem DB, sem bootstrap do framework.
| Documenta os tiers de degradação e a lógica de limbo.
|
*/

use App\Domain\Keys\KeyPriceAging;

describe('KeyPriceAging', function () {

    // ── calculateAgedPrice ────────────────────────────────────────────────

    describe('calculateAgedPrice()', function () {

        it('returns null when the key has been listed for less than 3 months', function () {
            expect(KeyPriceAging::calculateAgedPrice(2.00, 2))->toBeNull();
        });

        it('returns null at exactly 0 months (just listed)', function () {
            expect(KeyPriceAging::calculateAgedPrice(2.00, 0))->toBeNull();
        });

        it('applies ×1.4 at exactly 3 months', function () {
            // 2.00 × 1.4 = 2.80
            expect(KeyPriceAging::calculateAgedPrice(2.00, 3))->toEqualWithDelta(2.80, 0.001);
        });

        it('applies ×1.4 between 3 and 6 months', function () {
            // 2.00 × 1.4 = 2.80
            expect(KeyPriceAging::calculateAgedPrice(2.00, 5))->toEqualWithDelta(2.80, 0.001);
        });

        it('applies ×1.3 at exactly 6 months', function () {
            // 2.00 × 1.3 = 2.60
            expect(KeyPriceAging::calculateAgedPrice(2.00, 6))->toEqualWithDelta(2.60, 0.001);
        });

        it('applies ×1.3 between 6 and 9 months', function () {
            // 2.00 × 1.3 = 2.60
            expect(KeyPriceAging::calculateAgedPrice(2.00, 8))->toEqualWithDelta(2.60, 0.001);
        });

        it('applies ×1.2 at exactly 9 months', function () {
            // 2.00 × 1.2 = 2.40
            expect(KeyPriceAging::calculateAgedPrice(2.00, 9))->toEqualWithDelta(2.40, 0.001);
        });

        it('applies ×1.2 between 9 and 12 months', function () {
            // 2.00 × 1.2 = 2.40
            expect(KeyPriceAging::calculateAgedPrice(2.00, 11))->toEqualWithDelta(2.40, 0.001);
        });

        it('returns the floor price (€0.02) at exactly 12 months', function () {
            expect(KeyPriceAging::calculateAgedPrice(2.00, 12))->toEqualWithDelta(0.02, 0.001);
        });

        it('returns the floor price (€0.02) beyond 12 months', function () {
            expect(KeyPriceAging::calculateAgedPrice(2.00, 24))->toEqualWithDelta(0.02, 0.001);
        });
    });

    // ── calculateLimboPrice ───────────────────────────────────────────────

    describe('calculateLimboPrice()', function () {

        it('returns the floor price when market price is below individual cost', function () {
            // mercado = 1.00 < custo = 2.00 → piso €0.02
            expect(KeyPriceAging::calculateLimboPrice(2.00, 1.00))->toEqualWithDelta(0.02, 0.001);
        });

        it('returns 10% of market price when market price equals individual cost', function () {
            // O original usa < estrito: igualdade cai no else → market × 0.10
            // mercado = custo = 2.00 → 2.00 × 0.10 = 0.20
            expect(KeyPriceAging::calculateLimboPrice(2.00, 2.00))->toEqualWithDelta(0.20, 0.001);
        });

        it('returns 10% of market price when market price exceeds individual cost', function () {
            // mercado = 5.00 > custo = 2.00 → 5.00 × 0.10 = 0.50
            expect(KeyPriceAging::calculateLimboPrice(2.00, 5.00))->toEqualWithDelta(0.50, 0.001);
        });

        it('returns 10% of market price for a high-value key', function () {
            // mercado = 30.00 > custo = 1.50 → 30.00 × 0.10 = 3.00
            expect(KeyPriceAging::calculateLimboPrice(1.50, 30.00))->toEqualWithDelta(3.00, 0.001);
        });
    });
});
