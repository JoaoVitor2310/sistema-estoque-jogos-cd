<?php

use App\Domain\Bundles\LaunchPriceResearch;
use Illuminate\Support\Carbon;

describe('LaunchPriceResearch::isDue()', function () {

    it('is due for a bundle without launch prices inside the window', function () {
        $release = Carbon::parse('2026-10-10');

        expect(LaunchPriceResearch::isDue(false, $release, Carbon::parse('2026-10-10 12:00')))->toBeTrue()
            ->and(LaunchPriceResearch::isDue(false, $release, Carbon::parse('2026-10-13 23:59')))->toBeTrue();
    });

    it('is not due after the window, when today\'s price is no longer the launch price', function () {
        expect(LaunchPriceResearch::isDue(false, Carbon::parse('2026-10-10'), Carbon::parse('2026-10-14 00:00')))->toBeFalse();
    });

    it('is not due once the bundle already has launch prices', function () {
        // Pesquisar de novo sobrescreveria o preço de lançamento com o de hoje.
        expect(LaunchPriceResearch::isDue(true, Carbon::parse('2026-10-10'), Carbon::parse('2026-10-10 12:00')))->toBeFalse();
    });
});
