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
        it('returns the human-readable name for each marketplace', function () {
            expect(Marketplace::Gamivo->label())->toBe('Gamivo')
                ->and(Marketplace::G2A->label())->toBe('G2A')
                ->and(Marketplace::Kinguin->label())->toBe('Kinguin')
                ->and(Marketplace::Troca->label())->toBe('Troca');
        });
    });

    describe('useClientPriceAsSalePrice()', function () {
        it('is true for Gamivo', function () {
            expect(Marketplace::Gamivo->useClientPriceAsSalePrice())->toBeTrue();
        });

        it('is false for G2A', function () {
            expect(Marketplace::G2A->useClientPriceAsSalePrice())->toBeFalse();
        });
    });

    describe('integer values', function () {
        it('has the correct id for every case', function () {
            expect(Marketplace::G2A->value)->toBe(2)
                ->and(Marketplace::Gamivo->value)->toBe(3)
                ->and(Marketplace::Kinguin->value)->toBe(4)
                ->and(Marketplace::Troca->value)->toBe(7);
        });
    });
});
