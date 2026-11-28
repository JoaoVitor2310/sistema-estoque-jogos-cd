<?php

use App\Domain\Trades\ChatDelivery;

describe('ChatDelivery', function () {

    it('delivers only the offer lines that still exist in the trade', function () {
        expect(ChatDelivery::isDeliverable(10))->toBeTrue()
            ->and(ChatDelivery::isDeliverable(null))->toBeFalse();
    });

    it('points out the requested lines that are not in the offer', function () {
        expect(ChatDelivery::outsideOffer([10, 99, 12], [10, 12]))->toBe([99])
            ->and(ChatDelivery::outsideOffer([10], [10, 12]))->toBe([]);
    });

    it('lists the lines still without a key, in the order of the offer', function () {
        $keys = [10 => 'AAAAA-BBBBB-CCCCC', 11 => null, 12 => '   '];

        expect(ChatDelivery::missingKeys([12, 10, 11], $keys))->toBe([12, 11]);
    });

    it('counts a line that was never written as missing its key', function () {
        expect(ChatDelivery::missingKeys([10], []))->toBe([10]);
    });

    it('adds the prices cent by cent, so the total matches the lines read', function () {
        // Em ponto flutuante, 0,1 + 0,2 é 0,30000000000000004
        expect(ChatDelivery::agreedTotalCents([0.1, 0.2]))->toBe(30)
            ->and(ChatDelivery::agreedTotalCents([2.46, 3.34]))->toBe(580)
            ->and(ChatDelivery::agreedTotalCents([]))->toBe(0);
    });
});
