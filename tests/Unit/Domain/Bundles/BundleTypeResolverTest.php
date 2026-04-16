<?php

/*
|--------------------------------------------------------------------------
| BundleTypeResolver — unit tests
|--------------------------------------------------------------------------
|
| PHP puro — sem DB, sem bootstrap do framework.
|
*/

use App\Domain\Bundles\BundleTypeResolver;

describe('BundleTypeResolver', function () {

    describe('resolve()', function () {

        it('returns "choice" when title contains the word Choice', function () {
            expect(BundleTypeResolver::resolve('Humble Choice March 2025'))->toBe('choice');
        });

        it('is case-insensitive when matching Choice', function () {
            expect(BundleTypeResolver::resolve('humble choice march 2025'))->toBe('choice');
            expect(BundleTypeResolver::resolve('HUMBLE CHOICE MARCH 2025'))->toBe('choice');
        });

        it('returns "bundle" when title does not contain Choice', function () {
            expect(BundleTypeResolver::resolve('Humble Bundle: Build Your Own'))->toBe('bundle');
        });

        it('returns "bundle" for a generic bundle title', function () {
            expect(BundleTypeResolver::resolve('Fanatical Star Deal'))->toBe('bundle');
        });

        it('exposes TYPE_CHOICE and TYPE_BUNDLE constants', function () {
            expect(BundleTypeResolver::TYPE_CHOICE)->toBe('choice');
            expect(BundleTypeResolver::TYPE_BUNDLE)->toBe('bundle');
        });
    });
});
