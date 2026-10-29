<?php

use App\Domain\Enums\SupplierCategory;
use App\Domain\Pricing\OfferCalculator;

describe('SupplierCategory::initialProfitPercent()', function () {

    it('gives each category its initial profit', function (?SupplierCategory $category, int $percent) {
        expect(SupplierCategory::initialProfitPercent($category))->toBe($percent);
    })->with([
        'normal (no category)' => [null, 70],
        'vip' => [SupplierCategory::Vip, 80],
        'retail' => [SupplierCategory::Retail, 60],
    ]);

    it('gives the normal supplier the same profit as supplier prospecting', function () {
        // O Normal é o supplier novo: os dois percentuais andam juntos
        expect(SupplierCategory::initialProfitPercent(null))->toBe(OfferCalculator::NEW_SUPPLIER_PROFIT_PERCENT);
    });

    it('gives no initial profit to a blocked supplier', function () {
        expect(SupplierCategory::initialProfitPercent(SupplierCategory::Blocked))->toBeNull();
    });
});
