<?php

/*
|--------------------------------------------------------------------------
| MinimumMarginPolicy — unit tests
|--------------------------------------------------------------------------
|
| PHP puro — sem DB, sem bootstrap do framework.
| Fonte única da margem mínima do min_api. Idade tem prioridade sobre custo.
|
| Regras first-match-wins (não listada):
|   1. >= UNLISTED_AGING_MONTHS meses     → UNLISTED_AGING_MARGIN
|   2. >= UNLISTED_MODERATE_MONTHS meses  → UNLISTED_MODERATE_MARGIN
|   3. custo > VERY_HIGH_COST_THRESHOLD   → VERY_HIGH_COST_MARGIN
|   4. custo > HIGH_COST_THRESHOLD        → HIGH_COST_MARGIN
|   5. custo < LOW_COST_THRESHOLD         → LOW_COST_MARGIN
|   6. default                           → DEFAULT_MARGIN
|
*/

use App\Domain\Keys\KeyEligibility;
use App\Domain\Pricing\MinimumMarginPolicy;
use Carbon\Carbon;

describe('MinimumMarginPolicy::requiredMargin()', function () {

    // ── Idade tem prioridade sobre custo ──────────────────────────────────

    it('requires the aging margin for a key aged >= UNLISTED_AGING_MONTHS, ignoring cost (15%)', function () {
        // custo alto que isoladamente pediria outro tier, mas a idade vence
        expect(MinimumMarginPolicy::requiredMargin(20.0, Carbon::now()->subMonths(7)))
            ->toEqualWithDelta(MinimumMarginPolicy::UNLISTED_AGING_MARGIN, 0.0001);
    });

    it('applies the aging tier exactly at the UNLISTED_AGING_MONTHS boundary', function () {
        expect(MinimumMarginPolicy::requiredMargin(5.0, Carbon::now()->subMonths(MinimumMarginPolicy::UNLISTED_AGING_MONTHS)->subDay()))
            ->toEqualWithDelta(MinimumMarginPolicy::UNLISTED_AGING_MARGIN, 0.0001);
    });

    it('requires the moderate margin for a key aged >= UNLISTED_MODERATE_MONTHS and below the aging tier, ignoring cost (40%)', function () {
        // custo baixo que isoladamente pediria outro tier, mas a idade (moderada) vence
        expect(MinimumMarginPolicy::requiredMargin(0.5, Carbon::now()->subMonths(5)))
            ->toEqualWithDelta(MinimumMarginPolicy::UNLISTED_MODERATE_MARGIN, 0.0001);
    });

    it('applies the moderate tier exactly at the UNLISTED_MODERATE_MONTHS boundary', function () {
        expect(MinimumMarginPolicy::requiredMargin(5.0, Carbon::now()->subMonths(MinimumMarginPolicy::UNLISTED_MODERATE_MONTHS)->subDay()))
            ->toEqualWithDelta(MinimumMarginPolicy::UNLISTED_MODERATE_MARGIN, 0.0001);
    });

    it('falls through to cost tiers for a key just under UNLISTED_MODERATE_MONTHS (60%)', function () {
        expect(MinimumMarginPolicy::requiredMargin(5.0, Carbon::now()->subMonths(2)))
            ->toEqualWithDelta(MinimumMarginPolicy::DEFAULT_MARGIN, 0.0001);
    });

    // ── Tiers de custo (key jovem, sem arm de idade) ──────────────────────

    it('requires the very-high-cost margin when cost is above VERY_HIGH_COST_THRESHOLD (40%)', function () {
        expect(MinimumMarginPolicy::requiredMargin(20.0, Carbon::now()))
            ->toEqualWithDelta(MinimumMarginPolicy::VERY_HIGH_COST_MARGIN, 0.0001);
    });

    it('requires the high-cost margin when cost is above HIGH_COST_THRESHOLD and at or below VERY_HIGH_COST_THRESHOLD (45%)', function () {
        expect(MinimumMarginPolicy::requiredMargin(13.0, Carbon::now()))
            ->toEqualWithDelta(MinimumMarginPolicy::HIGH_COST_MARGIN, 0.0001);
    });

    it('applies the high-cost tier at exactly VERY_HIGH_COST_THRESHOLD (not above it)', function () {
        expect(MinimumMarginPolicy::requiredMargin(MinimumMarginPolicy::VERY_HIGH_COST_THRESHOLD, Carbon::now()))
            ->toEqualWithDelta(MinimumMarginPolicy::HIGH_COST_MARGIN, 0.0001);
    });

    it('requires the low-cost margin when cost is below LOW_COST_THRESHOLD (55%)', function () {
        expect(MinimumMarginPolicy::requiredMargin(0.5, Carbon::now()))
            ->toEqualWithDelta(MinimumMarginPolicy::LOW_COST_MARGIN, 0.0001);
    });

    it('applies the default tier at exactly LOW_COST_THRESHOLD (not below it)', function () {
        expect(MinimumMarginPolicy::requiredMargin(MinimumMarginPolicy::LOW_COST_THRESHOLD, Carbon::now()))
            ->toEqualWithDelta(MinimumMarginPolicy::DEFAULT_MARGIN, 0.0001);
    });

    it('requires the default margin between LOW_COST_THRESHOLD and HIGH_COST_THRESHOLD (60%)', function () {
        expect(MinimumMarginPolicy::requiredMargin(5.0, Carbon::now()))
            ->toEqualWithDelta(MinimumMarginPolicy::DEFAULT_MARGIN, 0.0001);
    });

    it('applies the default tier at exactly HIGH_COST_THRESHOLD (not above it)', function () {
        expect(MinimumMarginPolicy::requiredMargin(MinimumMarginPolicy::HIGH_COST_THRESHOLD, Carbon::now()))
            ->toEqualWithDelta(MinimumMarginPolicy::DEFAULT_MARGIN, 0.0001);
    });

    // ── Guarda de custo zero ──────────────────────────────────────────────

    it('treats zero cost as FLOOR and falls into the low-cost tier', function () {
        expect(MinimumMarginPolicy::requiredMargin(0.0, Carbon::now()))
            ->toEqualWithDelta(MinimumMarginPolicy::LOW_COST_MARGIN, 0.0001);
    });
});

describe('MinimumMarginPolicy::minApi()', function () {

    it('applies the required margin to the cost', function () {
        expect(MinimumMarginPolicy::minApi(5.0, Carbon::now()))
            ->toEqualWithDelta(8.00, 0.001);
    });

    it('uses the aging margin for an old (but not floor-eligible) key', function () {
        expect(MinimumMarginPolicy::minApi(2.0, Carbon::now()->subMonths(7)))
            ->toEqualWithDelta(2.30, 0.001);
    });

    it('uses the moderate margin for a moderately aged key', function () {
        expect(MinimumMarginPolicy::minApi(2.0, Carbon::now()->subMonths(5)))
            ->toEqualWithDelta(2.80, 0.001);
    });

    it('applies the high-cost tier for a young expensive key', function () {
        expect(MinimumMarginPolicy::minApi(13.0, Carbon::now()))
            ->toEqualWithDelta(18.85, 0.001);
    });

    it('applies the low-cost tier for a young cheap key', function () {
        expect(MinimumMarginPolicy::minApi(0.5, Carbon::now()))
            ->toEqualWithDelta(0.78, 0.001);
    });

    it('rounds to 2 decimal places', function () {
        $value = MinimumMarginPolicy::minApi(13.0, Carbon::now());

        expect(round($value, 2))->toBe($value);
    });

    it('never returns below the equivalent of the zero-cost guard', function () {
        expect(MinimumMarginPolicy::minApi(0.0, Carbon::now()))
            ->toEqualWithDelta(0.03, 0.001);
    });

    // ── Overrides absolutos — expiração próxima ────────────────────────────

    it('returns FLOOR when the key expires within EXPIRY_PRICE_FLOOR_DAYS', function () {
        expect(MinimumMarginPolicy::minApi(20.0, Carbon::now(), expiresAt: Carbon::now()->addDays(10)))
            ->toBe(0.02);
    });

    it('applies the floor at the exact EXPIRY_PRICE_FLOOR_DAYS boundary', function () {
        expect(MinimumMarginPolicy::minApi(20.0, Carbon::now(), expiresAt: Carbon::now()->addDays(30)))
            ->toBe(0.02);
    });

    it('does not apply the expiry floor when expiration is further than EXPIRY_PRICE_FLOOR_DAYS away', function () {
        expect(MinimumMarginPolicy::minApi(20.0, Carbon::now(), expiresAt: Carbon::now()->addDays(31)))
            ->toEqualWithDelta(28.0, 0.001);
    });

    it('does not apply the expiry floor when expiresAt is null', function () {
        expect(MinimumMarginPolicy::minApi(20.0, Carbon::now(), expiresAt: null))
            ->toEqualWithDelta(28.0, 0.001);
    });

    it('does not apply the expiry floor when the key already expired', function () {
        expect(MinimumMarginPolicy::minApi(20.0, Carbon::now(), expiresAt: Carbon::now()->subDay()))
            ->toEqualWithDelta(28.0, 0.001);
    });

    // ── Overrides absolutos — estoque muito antigo ─────────────────────────

    it('returns FLOOR when the key was acquired >= OLD_KEY_MONTHS ago, regardless of cost', function () {
        expect(MinimumMarginPolicy::minApi(20.0, Carbon::now()->subMonths(9)))
            ->toBe(0.02);
    });

    it('never regresses to a margin-based value once OLD_KEY_MONTHS is reached, even if listed for a while', function () {
        // acquired_at >= OLD_KEY_MONTHS permanece verdadeiro para sempre — mesmo com
        // listed_at recente, a regra de estoque antigo continua vencendo
        expect(MinimumMarginPolicy::minApi(
            individualCost: 20.0,
            acquiredAt: Carbon::now()->subMonths(13),
            listedAt: Carbon::now()->subMonths(3),
        ))->toBe(0.02);
    });

    it('applies the floor exactly at the OLD_KEY_MONTHS boundary', function () {
        expect(MinimumMarginPolicy::minApi(20.0, Carbon::now()->subMonths(KeyEligibility::OLD_KEY_MONTHS)->subDay()))
            ->toBe(0.02);
    });

    it('does not apply the old-stock floor just under OLD_KEY_MONTHS', function () {
        // 7 meses — ainda não bate OLD_KEY_MONTHS (8), cai na regra de aging
        expect(MinimumMarginPolicy::minApi(20.0, Carbon::now()->subMonths(7)))
            ->toEqualWithDelta(23.0, 0.001);
    });
});

describe('MinimumMarginPolicy::requiredMargin() — listed-time decay', function () {

    it('requires the listed-aging margin for a key listed >= LISTED_AGING_MONTHS, ignoring cost (20%)', function () {
        expect(MinimumMarginPolicy::requiredMargin(20.0, Carbon::now()->subMonths(20), Carbon::now()->subMonths(7)))
            ->toEqualWithDelta(MinimumMarginPolicy::LISTED_AGING_MARGIN, 0.0001);
    });

    it('applies the listed-aging tier exactly at the LISTED_AGING_MONTHS boundary', function () {
        expect(MinimumMarginPolicy::requiredMargin(5.0, Carbon::now()->subMonths(20), Carbon::now()->subMonths(6)->subDay()))
            ->toEqualWithDelta(MinimumMarginPolicy::LISTED_AGING_MARGIN, 0.0001);
    });

    it('requires the listed-moderate margin for a key listed >= LISTED_MODERATE_MONTHS and below the aging tier (30%)', function () {
        expect(MinimumMarginPolicy::requiredMargin(5.0, Carbon::now()->subMonths(20), Carbon::now()->subMonths(5)))
            ->toEqualWithDelta(MinimumMarginPolicy::LISTED_MODERATE_MARGIN, 0.0001);
    });

    it('requires the listed-early margin for a key listed >= LISTED_EARLY_MONTHS and below the moderate tier (40%)', function () {
        expect(MinimumMarginPolicy::requiredMargin(5.0, Carbon::now()->subMonths(20), Carbon::now()->subMonths(3)->subDays(15)))
            ->toEqualWithDelta(MinimumMarginPolicy::LISTED_EARLY_MARGIN, 0.0001);
    });

    it('falls through to cost tiers when listed less than LISTED_EARLY_MONTHS', function () {
        expect(MinimumMarginPolicy::requiredMargin(5.0, Carbon::now()->subMonths(20), Carbon::now()->subMonth()))
            ->toEqualWithDelta(MinimumMarginPolicy::DEFAULT_MARGIN, 0.0001);
    });

    it('applies cost tiers (not acquired-time aging) when listed, even if acquired a long time ago', function () {
        expect(MinimumMarginPolicy::requiredMargin(20.0, Carbon::now()->subMonths(20), Carbon::now()->subMonth()))
            ->toEqualWithDelta(MinimumMarginPolicy::VERY_HIGH_COST_MARGIN, 0.0001);
    });

    it('ignores acquired-time aging tiers entirely once listedAt is provided', function () {
        $acquiredAt = Carbon::now()->subMonths(7);

        $withoutListedAt = MinimumMarginPolicy::requiredMargin(5.0, $acquiredAt);
        $withListedAt = MinimumMarginPolicy::requiredMargin(5.0, $acquiredAt, Carbon::now()->subMonth());

        expect($withoutListedAt)->toEqualWithDelta(MinimumMarginPolicy::UNLISTED_AGING_MARGIN, 0.0001)
            ->and($withListedAt)->toEqualWithDelta(MinimumMarginPolicy::DEFAULT_MARGIN, 0.0001);
    });
});

describe('MinimumMarginPolicy::minApi() — limbo (listed >= LIMBO_MONTHS_THRESHOLD)', function () {

    it('returns FLOOR when listed >= LIMBO_MONTHS_THRESHOLD, regardless of cost', function () {
        expect(MinimumMarginPolicy::minApi(
            individualCost: 20.0,
            acquiredAt: Carbon::now()->subMonths(20),
            listedAt: Carbon::now()->subMonths(11),
        ))->toBe(0.02);
    });

    it('applies the limbo floor exactly at the LIMBO_MONTHS_THRESHOLD boundary', function () {
        expect(MinimumMarginPolicy::minApi(
            individualCost: 20.0,
            acquiredAt: Carbon::now()->subMonths(20),
            listedAt: Carbon::now()->subMonths(10)->subDay(),
        ))->toBe(0.02);
    });

    it('does not apply the limbo floor when listed less than LIMBO_MONTHS_THRESHOLD', function () {
        // acquired_at e listed_at recentes o bastante pra não acionar o floor de estoque antigo
        expect(MinimumMarginPolicy::minApi(
            individualCost: 2.0,
            acquiredAt: Carbon::now()->subMonths(5),
            listedAt: Carbon::now()->subMonths(5),
        ))->toEqualWithDelta(2.60, 0.001);
    });

    it('does not apply the limbo floor when listedAt is null', function () {
        expect(MinimumMarginPolicy::minApi(
            individualCost: 2.0,
            acquiredAt: Carbon::now()->subMonths(5),
            listedAt: null,
        ))->toEqualWithDelta(2.80, 0.001);
    });

    it('expiry-soon override still wins over the limbo floor', function () {
        expect(MinimumMarginPolicy::minApi(
            individualCost: 2.0,
            acquiredAt: Carbon::now()->subMonths(20),
            listedAt: Carbon::now()->subMonths(11),
            expiresAt: Carbon::now()->addDays(5),
        ))->toBe(0.02);
    });
});

describe('MinimumMarginPolicy::minApi() — old-stock floor survives listing', function () {

    it('keeps the old-stock floor even after the key is listed, regardless of listed-time', function () {
        expect(MinimumMarginPolicy::minApi(
            individualCost: 20.0,
            acquiredAt: Carbon::now()->subMonths(13),
            listedAt: Carbon::now()->subMonth(),
        ))->toBe(0.02);
    });

    it('applies the old-stock floor for a listed key even when listed for only a few days', function () {
        expect(MinimumMarginPolicy::minApi(
            individualCost: 20.0,
            acquiredAt: Carbon::now()->subMonths(9),
            listedAt: Carbon::now()->subDays(2),
        ))->toBe(0.02);
    });
});
