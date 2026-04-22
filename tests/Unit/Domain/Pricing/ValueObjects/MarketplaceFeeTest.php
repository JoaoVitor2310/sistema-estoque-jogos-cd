<?php

/*
|--------------------------------------------------------------------------
| MarketplaceFee — unit tests
|--------------------------------------------------------------------------
|
| Pure PHP — no DB, no framework bootstrap.
|
*/

use App\Domain\Pricing\ValueObjects\MarketplaceFee;

describe('MarketplaceFee', function () {

    describe('constructor', function () {
        it('stores all fee values as readonly properties', function () {
            $fee = new MarketplaceFee(
                percentualMenor: 0.06,
                fixoMenor: 0.25,
                percentualMaior: 0.08,
                fixoMaior: 0.40,
            );

            expect($fee->percentualMenor)->toBe(0.06)
                ->and($fee->fixoMenor)->toBe(0.25)
                ->and($fee->percentualMaior)->toBe(0.08)
                ->and($fee->fixoMaior)->toBe(0.40);
        });

        it('accepts boundary values of 0 and 1 for percentuals', function () {
            $fee = new MarketplaceFee(0.0, 0.0, 1.0, 0.0);

            expect($fee->percentualMenor)->toBe(0.0)
                ->and($fee->percentualMaior)->toBe(1.0);
        });
    });

    describe('validation', function () {
        it('throws when percentualMenor is negative', function () {
            expect(fn () => new MarketplaceFee(-0.01, 0.25, 0.08, 0.40))
                ->toThrow(\InvalidArgumentException::class);
        });

        it('throws when percentualMenor exceeds 1', function () {
            expect(fn () => new MarketplaceFee(1.01, 0.25, 0.08, 0.40))
                ->toThrow(\InvalidArgumentException::class);
        });

        it('throws when percentualMaior is negative', function () {
            expect(fn () => new MarketplaceFee(0.06, 0.25, -0.01, 0.40))
                ->toThrow(\InvalidArgumentException::class);
        });

        it('throws when percentualMaior exceeds 1', function () {
            expect(fn () => new MarketplaceFee(0.06, 0.25, 1.50, 0.40))
                ->toThrow(\InvalidArgumentException::class);
        });

        it('throws when fixoMenor is negative', function () {
            expect(fn () => new MarketplaceFee(0.06, -0.01, 0.08, 0.40))
                ->toThrow(\InvalidArgumentException::class);
        });

        it('throws when fixoMaior is negative', function () {
            expect(fn () => new MarketplaceFee(0.06, 0.25, 0.08, -0.01))
                ->toThrow(\InvalidArgumentException::class);
        });
    });

    describe('fromArray()', function () {
        it('builds the VO from an associative array', function () {
            $fee = MarketplaceFee::fromArray([
                'gamivoPercentualMenor' => 0.060,
                'gamivoFixoMenor' => 0.250,
                'gamivoPercentualMaior' => 0.080,
                'gamivoFixoMaior' => 0.400,
            ]);

            expect($fee->percentualMenor)->toBe(0.060)
                ->and($fee->fixoMenor)->toBe(0.250)
                ->and($fee->percentualMaior)->toBe(0.080)
                ->and($fee->fixoMaior)->toBe(0.400);
        });

        it('casts string values to float', function () {
            $fee = MarketplaceFee::fromArray([
                'gamivoPercentualMenor' => '0.06',
                'gamivoFixoMenor' => '0.25',
                'gamivoPercentualMaior' => '0.08',
                'gamivoFixoMaior' => '0.40',
            ]);

            expect($fee->percentualMenor)->toBeFloat()
                ->and($fee->percentualMenor)->toBe(0.06);
        });
    });
});
