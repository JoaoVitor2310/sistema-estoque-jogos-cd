<?php

/*
|--------------------------------------------------------------------------
| KeyEligibility — unit tests
|--------------------------------------------------------------------------
|
| PHP puro — sem DB, sem bootstrap do framework.
| Documenta cada regra de elegibilidade para autoSell() isoladamente,
| e o caso feliz em que todas as condições são satisfeitas.
|
*/

use App\Domain\Keys\KeyEligibility;
use Carbon\Carbon;

describe('KeyEligibility', function () {

    describe('isEligibleForAutoSell()', function () {

        // ── Caso feliz ────────────────────────────────────────────────────

        it('returns true when all conditions are met', function () {
            expect(KeyEligibility::isEligibleForAutoSell(
                gamivoId: '12345',
                keyCode: 'ABCDE-12345-FGHIJ',
                listedAt: null,
                soldAt: null,
                newestBundleRelease: null,
            ))->toBeTrue();
        });

        it('returns true when the bundle release is older than 21 days', function () {
            expect(KeyEligibility::isEligibleForAutoSell(
                gamivoId: '12345',
                keyCode: 'ABCDE-12345-FGHIJ',
                listedAt: null,
                soldAt: null,
                newestBundleRelease: Carbon::now()->subDays(22),
            ))->toBeTrue();
        });

        // ── Regra 1: idGamivo obrigatório ─────────────────────────────────

        it('returns false when gamivoId is null', function () {
            expect(KeyEligibility::isEligibleForAutoSell(
                gamivoId: null,
                keyCode: 'ABCDE-12345-FGHIJ',
                listedAt: null,
                soldAt: null,
                newestBundleRelease: null,
            ))->toBeFalse();
        });

        it('returns false when gamivoId is an empty string', function () {
            expect(KeyEligibility::isEligibleForAutoSell(
                gamivoId: '',
                keyCode: 'ABCDE-12345-FGHIJ',
                listedAt: null,
                soldAt: null,
                newestBundleRelease: null,
            ))->toBeFalse();
        });

        // ── Regra 2: não listada ──────────────────────────────────────────

        it('returns false when the key is already listed for sale', function () {
            expect(KeyEligibility::isEligibleForAutoSell(
                gamivoId: '12345',
                keyCode: 'ABCDE-12345-FGHIJ',
                listedAt: Carbon::now()->subDays(5),
                soldAt: null,
                newestBundleRelease: null,
            ))->toBeFalse();
        });

        // ── Regra 3: não vendida ──────────────────────────────────────────

        it('returns false when the key is already sold', function () {
            expect(KeyEligibility::isEligibleForAutoSell(
                gamivoId: '12345',
                keyCode: 'ABCDE-12345-FGHIJ',
                listedAt: null,
                soldAt: Carbon::now()->subDays(1),
                newestBundleRelease: null,
            ))->toBeFalse();
        });

        // ── Regra 4: gift links ───────────────────────────────────────────

        it('returns false when the key code is a gift link (http URL)', function () {
            expect(KeyEligibility::isEligibleForAutoSell(
                gamivoId: '12345',
                keyCode: 'https://store.steampowered.com/gift/XXXXXXXX',
                listedAt: null,
                soldAt: null,
                newestBundleRelease: null,
            ))->toBeFalse();
        });

        it('returns false for gift links with https protocol', function () {
            expect(KeyEligibility::isEligibleForAutoSell(
                gamivoId: '12345',
                keyCode: 'https://www.humblebundle.com/gift?key=XXXXXX',
                listedAt: null,
                soldAt: null,
                newestBundleRelease: null,
            ))->toBeFalse();
        });

        it('returns true when keyCode is null (key not yet received)', function () {
            // key não recebida ainda não é gift link — não deve ser bloqueada por essa regra
            expect(KeyEligibility::isEligibleForAutoSell(
                gamivoId: '12345',
                keyCode: null,
                listedAt: null,
                soldAt: null,
                newestBundleRelease: null,
            ))->toBeTrue();
        });

        // ── Regra 5: bundle recente (21 dias) ────────────────────────────

        it('returns false when the newest bundle was released within 21 days', function () {
            expect(KeyEligibility::isEligibleForAutoSell(
                gamivoId: '12345',
                keyCode: 'ABCDE-12345-FGHIJ',
                listedAt: null,
                soldAt: null,
                newestBundleRelease: Carbon::now()->subDays(10),
            ))->toBeFalse();
        });

        it('returns true at exactly 21 days (boundary — uses strict > comparison, same as original SQL)', function () {
            // O original usa `release_date > NOW() - INTERVAL '21 days'` (estrito).
            // No 21º dia exato a condição não aciona → o jogo é elegível.
            expect(KeyEligibility::isEligibleForAutoSell(
                gamivoId: '12345',
                keyCode: 'ABCDE-12345-FGHIJ',
                listedAt: null,
                soldAt: null,
                newestBundleRelease: Carbon::now()->subDays(21)->subSecond(),
            ))->toBeTrue();
        });

        it('returns true at 22 days (bundle outside exclusion window)', function () {
            expect(KeyEligibility::isEligibleForAutoSell(
                gamivoId: '12345',
                keyCode: 'ABCDE-12345-FGHIJ',
                listedAt: null,
                soldAt: null,
                newestBundleRelease: Carbon::now()->subDays(22),
            ))->toBeTrue();
        });

        it('returns true when newestBundleRelease is null (game has no bundle)', function () {
            expect(KeyEligibility::isEligibleForAutoSell(
                gamivoId: '12345',
                keyCode: 'ABCDE-12345-FGHIJ',
                listedAt: null,
                soldAt: null,
                newestBundleRelease: null,
            ))->toBeTrue();
        });
    });

    // ── hasMinimumProfitForAutoSell() ─────────────────────────────────────────

    describe('hasMinimumProfitForAutoSell()', function () {

        // ── Idade ≥ AGING_KEY_MONTHS meses → exige 15% (AGING_KEY_MIN_API_MULTIPLIER - 1) ──

        it('returns true when profit is exactly 15% of cost for an aging key (>= 7 months)', function () {
            // custo=2.00, preço=2.30 → lucro=0.30 = 15% × 2.00 ✓
            expect(KeyEligibility::hasMinimumProfitForAutoSell(
                sellerPrice: 2.30,
                individualCost: 2.00,
                acquiredAt: Carbon::now()->subMonths(8),
            ))->toBeTrue();
        });

        it('returns false when profit is below 15% of cost for an aging key (>= 7 months)', function () {
            // custo=2.00, preço=2.29 → lucro=0.29 < 15% × 2.00 ✗
            expect(KeyEligibility::hasMinimumProfitForAutoSell(
                sellerPrice: 2.29,
                individualCost: 2.00,
                acquiredAt: Carbon::now()->subMonths(8),
            ))->toBeFalse();
        });

        it('applies the aging rule at exactly AGING_KEY_MONTHS (boundary)', function () {
            // 7 meses exatos satisfazem lt(now()->subMonths(7)) → arm de aging ativo
            // custo=2.00, preço=2.30 → lucro=0.30 = 15% × 2.00 ✓
            expect(KeyEligibility::hasMinimumProfitForAutoSell(
                sellerPrice: 2.30,
                individualCost: 2.00,
                acquiredAt: Carbon::now()->subMonths(7)->subDay(),
            ))->toBeTrue();
        });

        // ── Idade ≥ MODERATE_AGE_MONTHS meses (e < 7) → exige 40% ───────

        it('returns true when profit is exactly 40% of cost for a moderately aged key (>= 4 months)', function () {
            // custo=2.00, preço=2.80 → lucro=0.80 = 40% × 2.00 ✓
            expect(KeyEligibility::hasMinimumProfitForAutoSell(
                sellerPrice: 2.80,
                individualCost: 2.00,
                acquiredAt: Carbon::now()->subMonths(5),
            ))->toBeTrue();
        });

        it('returns false when profit is below 40% of cost for a moderately aged key (>= 4 months)', function () {
            // custo=2.00, preço=2.70 → lucro=0.70 < 40% × 2.00 ✗
            expect(KeyEligibility::hasMinimumProfitForAutoSell(
                sellerPrice: 2.70,
                individualCost: 2.00,
                acquiredAt: Carbon::now()->subMonths(5),
            ))->toBeFalse();
        });

        it('applies cost tiers to a key just under MODERATE_AGE_MONTHS (boundary)', function () {
            // 3 meses não satisfaz nenhum arm de idade → cai nos tiers de custo
            // custo=2.00 → default 60% → preço=3.20 → lucro=1.20 = 60% × 2.00 ✓
            expect(KeyEligibility::hasMinimumProfitForAutoSell(
                sellerPrice: 3.20,
                individualCost: 2.00,
                acquiredAt: Carbon::now()->subMonths(3),
            ))->toBeTrue();
        });

        // ── Custo > €15 → exige 40% de margem ───────────────────────────

        it('returns true when profit is exactly 40% of cost (cost > 15)', function () {
            // custo=20, preço=28 → lucro=8 = 40% × 20 ✓
            expect(KeyEligibility::hasMinimumProfitForAutoSell(
                sellerPrice: 28.0,
                individualCost: 20.0,
                acquiredAt: Carbon::now()->subMonths(1),
            ))->toBeTrue();
        });

        it('returns false when profit is below 40% of cost (cost > 15)', function () {
            // custo=20, preço=27.99 → lucro=7.99 < 40% × 20 ✗
            expect(KeyEligibility::hasMinimumProfitForAutoSell(
                sellerPrice: 27.99,
                individualCost: 20.0,
                acquiredAt: Carbon::now()->subMonths(1),
            ))->toBeFalse();
        });

        // ── Custo > €10 (e ≤ €15) → exige 45% de margem ─────────────────

        it('returns true when profit is exactly 45% of cost (cost > 10)', function () {
            // custo=13, preço=18.85 → lucro=5.85 = 45% × 13 ✓
            expect(KeyEligibility::hasMinimumProfitForAutoSell(
                sellerPrice: 18.85,
                individualCost: 13.0,
                acquiredAt: Carbon::now()->subMonths(1),
            ))->toBeTrue();
        });

        it('returns false when profit is below 45% of cost (cost > 10)', function () {
            // custo=13, preço=18.70 → lucro=5.70 < 45% × 13 ✗
            expect(KeyEligibility::hasMinimumProfitForAutoSell(
                sellerPrice: 18.70,
                individualCost: 13.0,
                acquiredAt: Carbon::now()->subMonths(1),
            ))->toBeFalse();
        });

        it('applies the > 10 rule to cost = 15 (boundary — not > 15)', function () {
            // custo=15 não satisfaz > 15, cai no tier > 10 → exige 45%
            // lucro=6.75 = 45% × 15 ✓
            expect(KeyEligibility::hasMinimumProfitForAutoSell(
                sellerPrice: 21.75,
                individualCost: 15.0,
                acquiredAt: Carbon::now()->subMonths(1),
            ))->toBeTrue();
        });

        // ── Custo < €1 → exige 55% de margem ────────────────────────────

        it('returns true when profit is exactly 55% of cost (cost < 1)', function () {
            // custo=0.50, preço=0.775 → lucro=0.275 = 55% × 0.50 ✓
            expect(KeyEligibility::hasMinimumProfitForAutoSell(
                sellerPrice: 0.775,
                individualCost: 0.50,
                acquiredAt: Carbon::now()->subMonths(1),
            ))->toBeTrue();
        });

        it('returns false when profit is below 55% of cost (cost < 1)', function () {
            // custo=0.50, preço=0.70 → lucro=0.20 < 55% × 0.50 ✗
            expect(KeyEligibility::hasMinimumProfitForAutoSell(
                sellerPrice: 0.70,
                individualCost: 0.50,
                acquiredAt: Carbon::now()->subMonths(1),
            ))->toBeFalse();
        });

        it('applies the default rule to cost = 1 (boundary — not < 1)', function () {
            // custo=1.0 não satisfaz < 1, cai no default → exige 60%
            // lucro=0.60 = 60% × 1.0 ✓
            expect(KeyEligibility::hasMinimumProfitForAutoSell(
                sellerPrice: 1.60,
                individualCost: 1.0,
                acquiredAt: Carbon::now()->subMonths(1),
            ))->toBeTrue();
        });

        // ── Default (€1 ≤ custo ≤ €10) → exige 60% de margem ────────────

        it('returns true when profit is exactly 60% of cost (default tier)', function () {
            // custo=5.0, preço=8.00 → lucro=3.00 = 60% × 5.0 ✓
            expect(KeyEligibility::hasMinimumProfitForAutoSell(
                sellerPrice: 8.00,
                individualCost: 5.0,
                acquiredAt: Carbon::now()->subMonths(1),
            ))->toBeTrue();
        });

        it('returns false when profit is below 60% of cost (default tier)', function () {
            // custo=5.0, preço=7.9 → lucro=2.9 < 60% × 5.0 ✗
            expect(KeyEligibility::hasMinimumProfitForAutoSell(
                sellerPrice: 7.9,
                individualCost: 5.0,
                acquiredAt: Carbon::now()->subMonths(1),
            ))->toBeFalse();
        });

        it('applies the default rule to cost = 10 (boundary — not > 10)', function () {
            // custo=10 não satisfaz > 10, cai no default → exige 60%
            // lucro=6.0 = 60% × 10 ✓
            expect(KeyEligibility::hasMinimumProfitForAutoSell(
                sellerPrice: 16.00,
                individualCost: 10.0,
                acquiredAt: Carbon::now()->subMonths(1),
            ))->toBeTrue();
        });

        // ── Lucro negativo ────────────────────────────────────────────────

        it('returns false when seller price is below cost', function () {
            expect(KeyEligibility::hasMinimumProfitForAutoSell(
                sellerPrice: 3.0,
                individualCost: 5.0,
                acquiredAt: Carbon::now()->subMonths(1),
            ))->toBeFalse();
        });

        it('returns false when seller price equals cost (zero profit)', function () {
            expect(KeyEligibility::hasMinimumProfitForAutoSell(
                sellerPrice: 5.0,
                individualCost: 5.0,
                acquiredAt: Carbon::now()->subMonths(1),
            ))->toBeFalse();
        });

        // ── Guarda de custo zero ──────────────────────────────────────────

        it('does not divide by zero when individual_cost is 0', function () {
            // custo tratado internamente como 0.01; qualquer preço positivo passa
            expect(KeyEligibility::hasMinimumProfitForAutoSell(
                sellerPrice: 1.0,
                individualCost: 0.0,
                acquiredAt: Carbon::now()->subMonths(1),
            ))->toBeTrue();
        });
    });

    // ── Constantes de aging ───────────────────────────────────────────────────

    describe('aging constants', function () {

        it('exposes OLD_KEY_MONTHS as 10', function () {
            expect(KeyEligibility::OLD_KEY_MONTHS)->toBe(10);
        });

        it('exposes AGING_KEY_MONTHS as 7', function () {
            expect(KeyEligibility::AGING_KEY_MONTHS)->toBe(7);
        });

        it('exposes AGING_KEY_MIN_API_MULTIPLIER as 1.15', function () {
            expect(KeyEligibility::AGING_KEY_MIN_API_MULTIPLIER)->toBe(1.15);
        });
    });
});
