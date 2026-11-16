<?php

use App\Domain\Enums\TradeNumberChange;

describe('TradeNumberChange::onSupplierChange()', function () {

    it('keeps the number when the supplier stays the same', function () {
        expect(TradeNumberChange::onSupplierChange(7, 7))->toBe(TradeNumberChange::Keep);
    });

    it('leaves a trade without supplier without a number', function () {
        expect(TradeNumberChange::onSupplierChange(null, null))->toBe(TradeNumberChange::Keep);
    });

    it('drops the number when the trade loses its supplier', function () {
        expect(TradeNumberChange::onSupplierChange(7, null))->toBe(TradeNumberChange::Drop);
    });

    it('takes the next number of the new supplier when the supplier changes', function () {
        expect(TradeNumberChange::onSupplierChange(7, 8))->toBe(TradeNumberChange::NextOfNewSupplier);
    });

    it('takes the next number of the supplier a trade without one gets', function () {
        expect(TradeNumberChange::onSupplierChange(null, 8))->toBe(TradeNumberChange::NextOfNewSupplier);
    });
});
