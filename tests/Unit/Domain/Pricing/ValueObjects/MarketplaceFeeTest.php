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
                percentLow:  0.06,
                fixedLow:    0.25,
                percentHigh: 0.08,
                fixedHigh:   0.40,
            );

            expect($fee->percentLow)->toBe(0.06)
                ->and($fee->fixedLow)->toBe(0.25)
                ->and($fee->percentHigh)->toBe(0.08)
                ->and($fee->fixedHigh)->toBe(0.40);
        });

        it('accepts boundary values of 0 and 1 for percentuals', function () {
            $fee = new MarketplaceFee(0.0, 0.0, 1.0, 0.0);

            expect($fee->percentLow)->toBe(0.0)
                ->and($fee->percentHigh)->toBe(1.0);
        });
    });

    describe('validation', function () {
        it('throws when percentLow is negative', function () {
            expect(fn () => new MarketplaceFee(-0.01, 0.25, 0.08, 0.40))
                ->toThrow(\InvalidArgumentException::class);
        });

        it('throws when percentLow exceeds 1', function () {
            expect(fn () => new MarketplaceFee(1.01, 0.25, 0.08, 0.40))
                ->toThrow(\InvalidArgumentException::class);
        });

        it('throws when percentHigh is negative', function () {
            expect(fn () => new MarketplaceFee(0.06, 0.25, -0.01, 0.40))
                ->toThrow(\InvalidArgumentException::class);
        });

        it('throws when percentHigh exceeds 1', function () {
            expect(fn () => new MarketplaceFee(0.06, 0.25, 1.50, 0.40))
                ->toThrow(\InvalidArgumentException::class);
        });

        it('throws when fixedLow is negative', function () {
            expect(fn () => new MarketplaceFee(0.06, -0.01, 0.08, 0.40))
                ->toThrow(\InvalidArgumentException::class);
        });

        it('throws when fixedHigh is negative', function () {
            expect(fn () => new MarketplaceFee(0.06, 0.25, 0.08, -0.01))
                ->toThrow(\InvalidArgumentException::class);
        });
    });

    describe('fromArray()', function () {
        it('builds the VO from an associative array', function () {
            $fee = MarketplaceFee::fromArray([
                'gamivo_percent_low'  => 0.060,
                'gamivo_fixed_low'    => 0.250,
                'gamivo_percent_high' => 0.080,
                'gamivo_fixed_high'   => 0.400,
            ]);

            expect($fee->percentLow)->toBe(0.060)
                ->and($fee->fixedLow)->toBe(0.250)
                ->and($fee->percentHigh)->toBe(0.080)
                ->and($fee->fixedHigh)->toBe(0.400);
        });

        it('casts string values to float', function () {
            $fee = MarketplaceFee::fromArray([
                'gamivo_percent_low'  => '0.06',
                'gamivo_fixed_low'    => '0.25',
                'gamivo_percent_high' => '0.08',
                'gamivo_fixed_high'   => '0.40',
            ]);

            expect($fee->percentLow)->toBeFloat()
                ->and($fee->percentLow)->toBe(0.06);
        });
    });
});
