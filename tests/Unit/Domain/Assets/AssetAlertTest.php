<?php

use App\Domain\Assets\AssetAlert;

it('reports drift when the gap reaches the threshold', function () {
    expect(AssetAlert::hasDrifted(2.00, 2.00 + AssetAlert::DOLLAR_PRICE_VARIATION_THRESHOLD))->toBeTrue();
});

it('reports no drift just under the threshold', function () {
    expect(AssetAlert::hasDrifted(2.00, 2.00 + AssetAlert::DOLLAR_PRICE_VARIATION_THRESHOLD - 0.01))->toBeFalse();
});

it('reports drift regardless of direction', function () {
    // Dólar que cai distorce o custo calculado tanto quanto dólar que sobe.
    expect(AssetAlert::hasDrifted(2.00, 1.00))->toBeTrue()
        ->and(AssetAlert::hasDrifted(1.00, 2.00))->toBeTrue();
});

it('reports no drift when the price did not move', function () {
    expect(AssetAlert::hasDrifted(2.00, 2.00))->toBeFalse();
});
