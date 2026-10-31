<?php

use App\Domain\Enums\PurchaseChannel;
use App\Domain\Pricing\MinimumMarginPolicy;
use App\Domain\Pricing\OfferCalculator;
use App\Domain\Pricing\SupplierOffer;

describe('SupplierOffer::line()', function () {

    it('offers the requested profit when it stays below the maximum offer', function () {
        $line = SupplierOffer::line(6.0, 70, 2.0, PurchaseChannel::SupplierTrade);

        expect($line->tf2Offer)->toEqualWithDelta(OfferCalculator::tf2Offer(6.0, 70, 2.0), 1e-9)
            ->and($line->isAtMax())->toBeFalse();
    });

    it('never offers above what the min_api allows paying', function () {
        // 30% de lucro fica abaixo da margem padrão de 50%: a oferta para na máxima
        $line = SupplierOffer::line(6.0, 30, 2.0, PurchaseChannel::SupplierTrade);

        $max = MinimumMarginPolicy::maxInitialCost(6.0, PurchaseChannel::SupplierTrade) / 2.0;

        expect($line->tf2Offer)->toEqualWithDelta($max, 1e-9)
            ->and($line->maxTf2Offer)->toEqualWithDelta($max, 1e-9)
            ->and($line->isAtMax())->toBeTrue();
    });

    it('does not reach the maximum offer at any initial category profit', function (int $profitPercent) {
        // A maior margem inicial é 55%: os lucros de categoria ficam acima dela
        foreach ([0.5, 1.2, 6.0, 15.0, 30.0] as $netIncome) {
            expect(SupplierOffer::line($netIncome, $profitPercent, 2.0, PurchaseChannel::SupplierTrade)->isAtMax())->toBeFalse();
        }
    })->with([60, 70, 80]);

    it('has no offer for a game that does not cover the fees, or without a TF2 price', function () {
        expect(SupplierOffer::line(0.0, 70, 2.0, PurchaseChannel::SupplierTrade))->toBeNull()
            ->and(SupplierOffer::line(-1.0, 70, 2.0, PurchaseChannel::SupplierTrade))->toBeNull()
            ->and(SupplierOffer::line(6.0, 70, 0.0, PurchaseChannel::SupplierTrade))->toBeNull();
    });
});

describe('SupplierOffer::isFinalRound()', function () {

    it('makes the third offer the final one, whatever its value', function () {
        // Oferta inicial e duas contrapropostas
        expect(SupplierOffer::isFinalRound(1))->toBeFalse()
            ->and(SupplierOffer::isFinalRound(2))->toBeFalse()
            ->and(SupplierOffer::isFinalRound(3))->toBeTrue()
            ->and(SupplierOffer::MAX_ROUNDS)->toBe(3);
    });

    it('refuses a round outside the negotiation', function (int $round) {
        expect(fn () => SupplierOffer::isFinalRound($round))->toThrow(InvalidArgumentException::class);
    })->with([0, 4]);
});

describe('SupplierOffer::roundProfitPercent()', function () {

    it('lowers the profit target one step per counter offer', function (float $first, int $round, float $expected) {
        expect(SupplierOffer::roundProfitPercent($first, $round))->toBe($expected);
    })->with([
        'normal, round 1' => [70.0, 1, 70.0],
        'normal, round 2' => [70.0, 2, 60.0],
        'normal, round 3' => [70.0, 3, 50.0],
        'vip, round 3' => [80.0, 3, 60.0],
        'retail, round 3' => [60.0, 3, 40.0],
    ]);

    it('refuses a round outside the negotiation', function () {
        expect(fn () => SupplierOffer::roundProfitPercent(70.0, 4))->toThrow(InvalidArgumentException::class);
    });
});
