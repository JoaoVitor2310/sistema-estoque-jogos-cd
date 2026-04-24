<?php

use App\Domain\Enums\Marketplace;

describe('Marketplace enum', function () {

    describe('from() / tryFrom()', function () {
        it('resolves from its integer id', function () {
            expect(Marketplace::from(3))->toBe(Marketplace::Gamivo);
        });

        it('returns null for an unknown id', function () {
            expect(Marketplace::tryFrom(99))->toBeNull();
        });
    });

    describe('label()', function () {
        it('returns the human-readable name for Gamivo', function () {
            expect(Marketplace::Gamivo->label())->toBe('Gamivo');
        });
    });

    describe('useClientPriceAsSalePrice()', function () {
        it('is true for Gamivo', function () {
            expect(Marketplace::Gamivo->useClientPriceAsSalePrice())->toBeTrue();
        });
    });

    describe('integer values', function () {
        it('has the correct id for Gamivo', function () {
            expect(Marketplace::Gamivo->value)->toBe(3);
        });
    });
});
