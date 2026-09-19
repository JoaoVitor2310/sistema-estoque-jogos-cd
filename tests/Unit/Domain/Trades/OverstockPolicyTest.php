<?php

use App\Domain\Trades\GameStock;
use App\Domain\Trades\OverstockPolicy;
use Carbon\Carbon;

function overstockedGameStock(array $overrides = []): GameStock
{
    return new GameStock(
        $overrides['normalizedName'] ?? 'half life',
        $overrides['region'] ?? null,
        $overrides['displayName'] ?? 'Half-Life',
        $overrides['stock'] ?? OverstockPolicy::MIN_STOCK,
        array_key_exists('oldestAcquiredAt', $overrides)
            ? $overrides['oldestAcquiredAt']
            : Carbon::parse('2026-01-01')->subDays(OverstockPolicy::MIN_AGE_DAYS),
        $overrides['soldInWindow'] ?? 0,
    );
}

function overstockPolicyNow(): Carbon
{
    return Carbon::parse('2026-01-01');
}

describe('OverstockPolicy::isOverstocked()', function () {

    it('blocks a game with enough stock, old enough and no sales in the window', function () {
        expect(OverstockPolicy::isOverstocked(overstockedGameStock(), overstockPolicyNow()))->toBeTrue();
    });

    it('allows a game below the minimum stock, however old and unsold', function () {
        $game = overstockedGameStock([
            'stock' => OverstockPolicy::MIN_STOCK - 1,
            'oldestAcquiredAt' => overstockPolicyNow()->copy()->subYears(2),
        ]);

        expect(OverstockPolicy::isOverstocked($game, overstockPolicyNow()))->toBeFalse();
    });

    it('allows a game whose oldest key is younger than the age threshold', function () {
        // Metade das keys que vendem leva mais de 24 dias: estoque recente
        // parado ainda é venda normal.
        $game = overstockedGameStock([
            'stock' => 30,
            'oldestAcquiredAt' => overstockPolicyNow()->copy()->subDays(OverstockPolicy::MIN_AGE_DAYS - 1),
        ]);

        expect(OverstockPolicy::isOverstocked($game, overstockPolicyNow()))->toBeFalse();
    });

    it('blocks on the exact age threshold', function () {
        $game = overstockedGameStock([
            'oldestAcquiredAt' => overstockPolicyNow()->copy()->subDays(OverstockPolicy::MIN_AGE_DAYS),
        ]);

        expect(OverstockPolicy::isOverstocked($game, overstockPolicyNow()))->toBeTrue();
    });

    it('allows an old and large stock that still drains within the coverage limit', function () {
        // 5 keys com 7 vendas na janela escoam em ~64 dias — o jogo vende.
        $game = overstockedGameStock(['stock' => 5, 'soldInWindow' => 7]);

        expect(OverstockPolicy::isOverstocked($game, overstockPolicyNow()))->toBeFalse();
    });

    it('blocks a game that sells but carries stock beyond the coverage limit', function () {
        // 9 keys com 4 vendas na janela escoam em ~203 dias.
        $game = overstockedGameStock(['stock' => 9, 'soldInWindow' => 4]);

        expect(OverstockPolicy::isOverstocked($game, overstockPolicyNow()))->toBeTrue();
    });

    it('allows a game exactly on the coverage limit', function () {
        // 4 keys com 3 vendas na janela escoam em 120 dias — o limite é passar dele.
        $game = overstockedGameStock(['stock' => 4, 'soldInWindow' => 3]);

        expect(OverstockPolicy::coverageDays($game))->toEqual(OverstockPolicy::MAX_COVERAGE_DAYS)
            ->and(OverstockPolicy::isOverstocked($game, overstockPolicyNow()))->toBeFalse();
    });

    it('blocks a coverage a fraction past the limit, without rounding it back into range', function () {
        // 483 keys com 361 vendas escoam em ~120,4 dias: arredondado daria 120 e
        // passaria como dentro do limite, que é "mais de 120".
        $game = overstockedGameStock(['stock' => 483, 'soldInWindow' => 361]);

        expect(OverstockPolicy::coverageDays($game))->toBeGreaterThan((float) OverstockPolicy::MAX_COVERAGE_DAYS)
            ->toBeLessThan(OverstockPolicy::MAX_COVERAGE_DAYS + 0.5)
            ->and(OverstockPolicy::isOverstocked($game, overstockPolicyNow()))->toBeTrue();
    });

    it('allows stock without an acquisition date', function () {
        // A regra só bloqueia a idade que ela consegue provar.
        expect(OverstockPolicy::isOverstocked(overstockedGameStock(['oldestAcquiredAt' => null]), overstockPolicyNow()))->toBeFalse();
    });
});

describe('OverstockPolicy::coverageDays()', function () {

    it('returns null when nothing sold in the window', function () {
        expect(OverstockPolicy::coverageDays(overstockedGameStock(['stock' => 10, 'soldInWindow' => 0])))->toBeNull();
    });

    it('projects the days the current stock takes to drain', function () {
        expect(OverstockPolicy::coverageDays(overstockedGameStock(['stock' => 6, 'soldInWindow' => 3])))->toEqual(180.0);
    });
});

describe('OverstockPolicy::salesWindowStart()', function () {

    it('spans exactly the window in whole days, today included', function () {
        // `sold_at` é data: hoje conta como um dos 90 dias.
        $now = Carbon::parse('2026-06-15 18:30:00');

        $start = OverstockPolicy::salesWindowStart($now);

        expect($start->toDateTimeString())->toBe('2026-03-18 00:00:00')
            ->and((int) $start->diffInDays($now->copy()->startOfDay()) + 1)->toBe(OverstockPolicy::SALES_WINDOW_DAYS);
    });
});
