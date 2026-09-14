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
| Compra direta (PurchaseChannel::BundleStore): a margem inicial (passos 3–6)
| vira BUNDLE_STORE_MARGIN fixa; decaimento por tempo e pisos são os mesmos.
|
*/

use App\Domain\Enums\PurchaseChannel;
use App\Domain\Keys\KeyEligibility;
use App\Domain\Pricing\MinimumMarginPolicy;
use Carbon\Carbon;

describe('MinimumMarginPolicy::requiredMargin()', function () {

    // ── Idade tem prioridade sobre custo ──────────────────────────────────

    it('requires the aging margin for a key aged >= UNLISTED_AGING_MONTHS, ignoring cost (15%)', function () {
        // custo alto que isoladamente pediria outro tier, mas a idade vence
        expect(MinimumMarginPolicy::requiredMargin(20.0, Carbon::now()->subMonths(7), PurchaseChannel::SupplierTrade))
            ->toEqualWithDelta(MinimumMarginPolicy::UNLISTED_AGING_MARGIN, 0.0001);
    });

    it('applies the aging tier exactly at the UNLISTED_AGING_MONTHS boundary', function () {
        expect(MinimumMarginPolicy::requiredMargin(5.0, Carbon::now()->subMonths(MinimumMarginPolicy::UNLISTED_AGING_MONTHS)->subDay(), PurchaseChannel::SupplierTrade))
            ->toEqualWithDelta(MinimumMarginPolicy::UNLISTED_AGING_MARGIN, 0.0001);
    });

    it('requires the moderate margin for a key aged >= UNLISTED_MODERATE_MONTHS and below the aging tier, ignoring cost (40%)', function () {
        // custo baixo que isoladamente pediria outro tier, mas a idade (moderada) vence
        expect(MinimumMarginPolicy::requiredMargin(0.5, Carbon::now()->subMonths(5), PurchaseChannel::SupplierTrade))
            ->toEqualWithDelta(MinimumMarginPolicy::UNLISTED_MODERATE_MARGIN, 0.0001);
    });

    it('applies the moderate tier exactly at the UNLISTED_MODERATE_MONTHS boundary', function () {
        expect(MinimumMarginPolicy::requiredMargin(5.0, Carbon::now()->subMonths(MinimumMarginPolicy::UNLISTED_MODERATE_MONTHS)->subDay(), PurchaseChannel::SupplierTrade))
            ->toEqualWithDelta(MinimumMarginPolicy::UNLISTED_MODERATE_MARGIN, 0.0001);
    });

    it('falls through to cost tiers for a key just under UNLISTED_MODERATE_MONTHS (50%)', function () {
        expect(MinimumMarginPolicy::requiredMargin(5.0, Carbon::now()->subMonths(2), PurchaseChannel::SupplierTrade))
            ->toEqualWithDelta(MinimumMarginPolicy::DEFAULT_MARGIN, 0.0001);
    });

    // ── Tiers de custo (key jovem, sem arm de idade) ──────────────────────

    it('requires the very-high-cost margin when cost is above VERY_HIGH_COST_THRESHOLD (40%)', function () {
        expect(MinimumMarginPolicy::requiredMargin(20.0, Carbon::now(), PurchaseChannel::SupplierTrade))
            ->toEqualWithDelta(MinimumMarginPolicy::VERY_HIGH_COST_MARGIN, 0.0001);
    });

    it('requires the high-cost margin when cost is above HIGH_COST_THRESHOLD and at or below VERY_HIGH_COST_THRESHOLD (45%)', function () {
        expect(MinimumMarginPolicy::requiredMargin(13.0, Carbon::now(), PurchaseChannel::SupplierTrade))
            ->toEqualWithDelta(MinimumMarginPolicy::HIGH_COST_MARGIN, 0.0001);
    });

    it('applies the high-cost tier at exactly VERY_HIGH_COST_THRESHOLD (not above it)', function () {
        expect(MinimumMarginPolicy::requiredMargin(MinimumMarginPolicy::VERY_HIGH_COST_THRESHOLD, Carbon::now(), PurchaseChannel::SupplierTrade))
            ->toEqualWithDelta(MinimumMarginPolicy::HIGH_COST_MARGIN, 0.0001);
    });

    it('requires the low-cost margin when cost is below LOW_COST_THRESHOLD (55%)', function () {
        expect(MinimumMarginPolicy::requiredMargin(0.5, Carbon::now(), PurchaseChannel::SupplierTrade))
            ->toEqualWithDelta(MinimumMarginPolicy::LOW_COST_MARGIN, 0.0001);
    });

    it('applies the default tier at exactly LOW_COST_THRESHOLD (not below it)', function () {
        expect(MinimumMarginPolicy::requiredMargin(MinimumMarginPolicy::LOW_COST_THRESHOLD, Carbon::now(), PurchaseChannel::SupplierTrade))
            ->toEqualWithDelta(MinimumMarginPolicy::DEFAULT_MARGIN, 0.0001);
    });

    it('requires the default margin between LOW_COST_THRESHOLD and HIGH_COST_THRESHOLD (50%)', function () {
        expect(MinimumMarginPolicy::requiredMargin(5.0, Carbon::now(), PurchaseChannel::SupplierTrade))
            ->toEqualWithDelta(MinimumMarginPolicy::DEFAULT_MARGIN, 0.0001);
    });

    it('applies the default tier at exactly HIGH_COST_THRESHOLD (not above it)', function () {
        expect(MinimumMarginPolicy::requiredMargin(MinimumMarginPolicy::HIGH_COST_THRESHOLD, Carbon::now(), PurchaseChannel::SupplierTrade))
            ->toEqualWithDelta(MinimumMarginPolicy::DEFAULT_MARGIN, 0.0001);
    });

    // ── Guarda de custo zero ──────────────────────────────────────────────

    it('treats zero cost as FLOOR and falls into the low-cost tier', function () {
        expect(MinimumMarginPolicy::requiredMargin(0.0, Carbon::now(), PurchaseChannel::SupplierTrade))
            ->toEqualWithDelta(MinimumMarginPolicy::LOW_COST_MARGIN, 0.0001);
    });
});

describe('MinimumMarginPolicy::minApi()', function () {

    it('applies the required margin to the cost', function () {
        expect(MinimumMarginPolicy::minApi(5.0, Carbon::now(), PurchaseChannel::SupplierTrade))
            ->toEqualWithDelta(7.50, 0.001);
    });

    it('uses the aging margin for an old (but not floor-eligible) key', function () {
        expect(MinimumMarginPolicy::minApi(2.0, Carbon::now()->subMonths(7), PurchaseChannel::SupplierTrade))
            ->toEqualWithDelta(2.30, 0.001);
    });

    it('uses the moderate margin for a moderately aged key', function () {
        expect(MinimumMarginPolicy::minApi(2.0, Carbon::now()->subMonths(5), PurchaseChannel::SupplierTrade))
            ->toEqualWithDelta(2.80, 0.001);
    });

    it('applies the high-cost tier for a young expensive key', function () {
        expect(MinimumMarginPolicy::minApi(13.0, Carbon::now(), PurchaseChannel::SupplierTrade))
            ->toEqualWithDelta(18.85, 0.001);
    });

    it('applies the low-cost tier for a young cheap key', function () {
        expect(MinimumMarginPolicy::minApi(0.5, Carbon::now(), PurchaseChannel::SupplierTrade))
            ->toEqualWithDelta(0.78, 0.001);
    });

    it('rounds to 2 decimal places', function () {
        $value = MinimumMarginPolicy::minApi(13.0, Carbon::now(), PurchaseChannel::SupplierTrade);

        expect(round($value, 2))->toBe($value);
    });

    it('never returns below the equivalent of the zero-cost guard', function () {
        expect(MinimumMarginPolicy::minApi(0.0, Carbon::now(), PurchaseChannel::SupplierTrade))
            ->toEqualWithDelta(0.03, 0.001);
    });

    // ── Overrides absolutos — expiração próxima ────────────────────────────

    it('returns FLOOR when the key expires within EXPIRY_PRICE_FLOOR_DAYS', function () {
        expect(MinimumMarginPolicy::minApi(20.0, Carbon::now(), PurchaseChannel::SupplierTrade, expiresAt: Carbon::now()->addDays(10)))
            ->toBe(0.02);
    });

    it('applies the floor at the exact EXPIRY_PRICE_FLOOR_DAYS boundary', function () {
        expect(MinimumMarginPolicy::minApi(20.0, Carbon::now(), PurchaseChannel::SupplierTrade, expiresAt: Carbon::now()->addDays(30)))
            ->toBe(0.02);
    });

    it('does not apply the expiry floor when expiration is further than EXPIRY_PRICE_FLOOR_DAYS away', function () {
        expect(MinimumMarginPolicy::minApi(20.0, Carbon::now(), PurchaseChannel::SupplierTrade, expiresAt: Carbon::now()->addDays(31)))
            ->toEqualWithDelta(28.0, 0.001);
    });

    it('does not apply the expiry floor when expiresAt is null', function () {
        expect(MinimumMarginPolicy::minApi(20.0, Carbon::now(), PurchaseChannel::SupplierTrade, expiresAt: null))
            ->toEqualWithDelta(28.0, 0.001);
    });

    it('does not apply the expiry floor when the key already expired', function () {
        expect(MinimumMarginPolicy::minApi(20.0, Carbon::now(), PurchaseChannel::SupplierTrade, expiresAt: Carbon::now()->subDay()))
            ->toEqualWithDelta(28.0, 0.001);
    });

    // ── Overrides absolutos — estoque muito antigo ─────────────────────────

    it('returns FLOOR when the key was acquired >= OLD_KEY_MONTHS ago, regardless of cost', function () {
        expect(MinimumMarginPolicy::minApi(20.0, Carbon::now()->subMonths(9), PurchaseChannel::SupplierTrade))
            ->toBe(0.02);
    });

    it('never regresses to a margin-based value once OLD_KEY_MONTHS is reached, even if listed for a while', function () {
        // acquired_at >= OLD_KEY_MONTHS permanece verdadeiro para sempre — mesmo com
        // listed_at recente, a regra de estoque antigo continua vencendo
        expect(MinimumMarginPolicy::minApi(
            individualCost: 20.0,
            acquiredAt: Carbon::now()->subMonths(13),
            channel: PurchaseChannel::SupplierTrade,
            listedAt: Carbon::now()->subMonths(3),
        ))->toBe(0.02);
    });

    it('applies the floor exactly at the OLD_KEY_MONTHS boundary', function () {
        expect(MinimumMarginPolicy::minApi(20.0, Carbon::now()->subMonths(KeyEligibility::OLD_KEY_MONTHS)->subDay(), PurchaseChannel::SupplierTrade))
            ->toBe(0.02);
    });

    it('does not apply the old-stock floor just under OLD_KEY_MONTHS', function () {
        // 7 meses — ainda não bate OLD_KEY_MONTHS (8), cai na regra de aging
        expect(MinimumMarginPolicy::minApi(20.0, Carbon::now()->subMonths(7), PurchaseChannel::SupplierTrade))
            ->toEqualWithDelta(23.0, 0.001);
    });
});

describe('MinimumMarginPolicy::requiredMargin() — listed-time decay', function () {

    it('requires the listed-aging margin for a key listed >= LISTED_AGING_MONTHS, ignoring cost (20%)', function () {
        expect(MinimumMarginPolicy::requiredMargin(20.0, Carbon::now()->subMonths(20), PurchaseChannel::SupplierTrade, Carbon::now()->subMonths(7)))
            ->toEqualWithDelta(MinimumMarginPolicy::LISTED_AGING_MARGIN, 0.0001);
    });

    it('applies the listed-aging tier exactly at the LISTED_AGING_MONTHS boundary', function () {
        expect(MinimumMarginPolicy::requiredMargin(5.0, Carbon::now()->subMonths(20), PurchaseChannel::SupplierTrade, Carbon::now()->subMonths(6)->subDay()))
            ->toEqualWithDelta(MinimumMarginPolicy::LISTED_AGING_MARGIN, 0.0001);
    });

    it('requires the listed-moderate margin for a key listed >= LISTED_MODERATE_MONTHS and below the aging tier (30%)', function () {
        expect(MinimumMarginPolicy::requiredMargin(5.0, Carbon::now()->subMonths(20), PurchaseChannel::SupplierTrade, Carbon::now()->subMonths(5)))
            ->toEqualWithDelta(MinimumMarginPolicy::LISTED_MODERATE_MARGIN, 0.0001);
    });

    it('requires the listed-early margin for a key listed >= LISTED_EARLY_MONTHS and below the moderate tier (40%)', function () {
        expect(MinimumMarginPolicy::requiredMargin(5.0, Carbon::now()->subMonths(20), PurchaseChannel::SupplierTrade, Carbon::now()->subMonths(3)->subDays(15)))
            ->toEqualWithDelta(MinimumMarginPolicy::LISTED_EARLY_MARGIN, 0.0001);
    });

    it('falls through to cost tiers when listed less than LISTED_EARLY_MONTHS', function () {
        expect(MinimumMarginPolicy::requiredMargin(5.0, Carbon::now()->subMonths(20), PurchaseChannel::SupplierTrade, Carbon::now()->subMonth()))
            ->toEqualWithDelta(MinimumMarginPolicy::DEFAULT_MARGIN, 0.0001);
    });

    it('applies cost tiers (not acquired-time aging) when listed, even if acquired a long time ago', function () {
        expect(MinimumMarginPolicy::requiredMargin(20.0, Carbon::now()->subMonths(20), PurchaseChannel::SupplierTrade, Carbon::now()->subMonth()))
            ->toEqualWithDelta(MinimumMarginPolicy::VERY_HIGH_COST_MARGIN, 0.0001);
    });

    it('ignores acquired-time aging tiers entirely once listedAt is provided', function () {
        $acquiredAt = Carbon::now()->subMonths(7);

        $withoutListedAt = MinimumMarginPolicy::requiredMargin(5.0, $acquiredAt, PurchaseChannel::SupplierTrade);
        $withListedAt = MinimumMarginPolicy::requiredMargin(5.0, $acquiredAt, PurchaseChannel::SupplierTrade, Carbon::now()->subMonth());

        expect($withoutListedAt)->toEqualWithDelta(MinimumMarginPolicy::UNLISTED_AGING_MARGIN, 0.0001)
            ->and($withListedAt)->toEqualWithDelta(MinimumMarginPolicy::DEFAULT_MARGIN, 0.0001);
    });
});

describe('MinimumMarginPolicy::minApi() — limbo (listed >= LIMBO_MONTHS_THRESHOLD)', function () {

    it('returns FLOOR when listed >= LIMBO_MONTHS_THRESHOLD, regardless of cost', function () {
        expect(MinimumMarginPolicy::minApi(
            individualCost: 20.0,
            acquiredAt: Carbon::now()->subMonths(20),
            channel: PurchaseChannel::SupplierTrade,
            listedAt: Carbon::now()->subMonths(11),
        ))->toBe(0.02);
    });

    it('applies the limbo floor exactly at the LIMBO_MONTHS_THRESHOLD boundary', function () {
        expect(MinimumMarginPolicy::minApi(
            individualCost: 20.0,
            acquiredAt: Carbon::now()->subMonths(20),
            channel: PurchaseChannel::SupplierTrade,
            listedAt: Carbon::now()->subMonths(10)->subDay(),
        ))->toBe(0.02);
    });

    it('does not apply the limbo floor when listed less than LIMBO_MONTHS_THRESHOLD', function () {
        // acquired_at e listed_at recentes o bastante pra não acionar o floor de estoque antigo
        expect(MinimumMarginPolicy::minApi(
            individualCost: 2.0,
            acquiredAt: Carbon::now()->subMonths(5),
            channel: PurchaseChannel::SupplierTrade,
            listedAt: Carbon::now()->subMonths(5),
        ))->toEqualWithDelta(2.60, 0.001);
    });

    it('does not apply the limbo floor when listedAt is null', function () {
        expect(MinimumMarginPolicy::minApi(
            individualCost: 2.0,
            acquiredAt: Carbon::now()->subMonths(5),
            channel: PurchaseChannel::SupplierTrade,
            listedAt: null,
        ))->toEqualWithDelta(2.80, 0.001);
    });

    it('expiry-soon override still wins over the limbo floor', function () {
        expect(MinimumMarginPolicy::minApi(
            individualCost: 2.0,
            acquiredAt: Carbon::now()->subMonths(20),
            channel: PurchaseChannel::SupplierTrade,
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
            channel: PurchaseChannel::SupplierTrade,
            listedAt: Carbon::now()->subMonth(),
        ))->toBe(0.02);
    });

    it('applies the old-stock floor for a listed key even when listed for only a few days', function () {
        expect(MinimumMarginPolicy::minApi(
            individualCost: 20.0,
            acquiredAt: Carbon::now()->subMonths(9),
            channel: PurchaseChannel::SupplierTrade,
            listedAt: Carbon::now()->subDays(2),
        ))->toBe(0.02);
    });
});

describe('MinimumMarginPolicy — direct bundle store purchase', function () {

    it('requires the bundle store margin from a young key, whatever its cost', function (float $cost) {
        expect(MinimumMarginPolicy::requiredMargin($cost, Carbon::now(), PurchaseChannel::BundleStore))
            ->toEqualWithDelta(MinimumMarginPolicy::BUNDLE_STORE_MARGIN, 0.0001);
    })->with([
        'low (<1)' => 0.5,
        'default (1-10)' => 5.0,
        'high (10-15)' => 13.0,
        'very high (>15)' => 20.0,
    ]);

    it('requires the bundle store margin from a key listed less than LISTED_EARLY_MONTHS ago', function () {
        expect(MinimumMarginPolicy::requiredMargin(5.0, Carbon::now()->subMonths(2), PurchaseChannel::BundleStore, Carbon::now()->subMonth()))
            ->toEqualWithDelta(MinimumMarginPolicy::BUNDLE_STORE_MARGIN, 0.0001);
    });

    it('decays by time exactly like a supplier trade once a time tier applies', function (Carbon $acquiredAt, ?Carbon $listedAt) {
        expect(MinimumMarginPolicy::requiredMargin(5.0, $acquiredAt, PurchaseChannel::BundleStore, $listedAt))
            ->toEqualWithDelta(MinimumMarginPolicy::requiredMargin(5.0, $acquiredAt, PurchaseChannel::SupplierTrade, $listedAt), 0.0001);
    })->with([
        'unlisted >= UNLISTED_MODERATE_MONTHS' => fn () => [Carbon::now()->subMonths(5), null],
        'unlisted >= UNLISTED_AGING_MONTHS' => fn () => [Carbon::now()->subMonths(7), null],
        'listed >= LISTED_EARLY_MONTHS' => fn () => [Carbon::now()->subMonths(7), Carbon::now()->subMonths(3)->subDays(15)],
        'listed >= LISTED_MODERATE_MONTHS' => fn () => [Carbon::now()->subMonths(7), Carbon::now()->subMonths(5)],
        'listed >= LISTED_AGING_MONTHS' => fn () => [Carbon::now()->subMonths(7), Carbon::now()->subMonths(7)],
    ]);

    // Invariante: as margens de tempo não passam de BUNDLE_STORE_MARGIN, então o
    // decaimento nunca sobe o piso da compra direta — nem ela exige mais que o
    // fornecedor na mesma situação.
    it('never requires more than a supplier trade in the same situation, nor more over time', function () {
        $costs = [0.5, 1.0, 5.0, 10.0, 13.0, 15.0, 20.0];
        $ages = [0, 2, 4, 5, 6, 7];

        foreach ($costs as $cost) {
            foreach ($ages as $acquiredMonths) {
                foreach ([null, 1, 3, 4, 5, 6, 7] as $listedMonths) {
                    $acquiredAt = Carbon::now()->subMonths($acquiredMonths)->subDay();
                    $listedAt = $listedMonths === null ? null : Carbon::now()->subMonths($listedMonths)->subDay();

                    $direct = MinimumMarginPolicy::requiredMargin($cost, $acquiredAt, PurchaseChannel::BundleStore, $listedAt);
                    $supplier = MinimumMarginPolicy::requiredMargin($cost, $acquiredAt, PurchaseChannel::SupplierTrade, $listedAt);

                    expect($direct)->toBeLessThanOrEqual($supplier + 0.0001)
                        ->and($direct)->toBeLessThanOrEqual(MinimumMarginPolicy::BUNDLE_STORE_MARGIN + 0.0001);
                }
            }
        }
    });

    it('prices a young direct purchase at cost plus the bundle store margin', function () {
        // 3.00 × 1.40 = 4.20 (fornecedor, faixa default: 3.00 × 1.50 = 4.50)
        expect(MinimumMarginPolicy::minApi(3.0, Carbon::now(), PurchaseChannel::BundleStore))->toBe(4.20);
    });

    it('keeps the absolute floors of a direct purchase', function () {
        expect(MinimumMarginPolicy::minApi(20.0, Carbon::now()->subMonths(KeyEligibility::OLD_KEY_MONTHS)->subDay(), PurchaseChannel::BundleStore))->toBe(0.02)
            ->and(MinimumMarginPolicy::minApi(20.0, Carbon::now(), PurchaseChannel::BundleStore, expiresAt: Carbon::now()->addDays(10)))->toBe(0.02)
            ->and(MinimumMarginPolicy::minApi(20.0, Carbon::now()->subMonths(20), PurchaseChannel::BundleStore, Carbon::now()->subMonths(11)))->toBe(0.02);
    });

    it('prices a Gamivo purchase like a supplier trade', function () {
        expect(MinimumMarginPolicy::requiredMargin(5.0, Carbon::now(), PurchaseChannel::Gamivo))
            ->toEqualWithDelta(MinimumMarginPolicy::DEFAULT_MARGIN, 0.0001);
    });
});
