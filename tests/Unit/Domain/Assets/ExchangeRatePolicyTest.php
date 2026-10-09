<?php

use App\Domain\Assets\ExchangeRatePolicy;

describe('ExchangeRatePolicy', function () {

    it('tolerates a rate up to six hours old', function () {
        expect(ExchangeRatePolicy::MAX_STALE_AGE_SECONDS)->toBe(6 * 3600);
    });
});
