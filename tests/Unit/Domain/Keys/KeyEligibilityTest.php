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

    // ── Constantes de aging ───────────────────────────────────────────────────

    describe('aging constants', function () {

        it('exposes OLD_KEY_MONTHS as 10', function () {
            expect(KeyEligibility::OLD_KEY_MONTHS)->toBe(10);
        });

        it('exposes AGING_KEY_MONTHS as 7', function () {
            expect(KeyEligibility::AGING_KEY_MONTHS)->toBe(7);
        });

        it('exposes AGING_KEY_MIN_API_MULTIPLIER as 1.2', function () {
            expect(KeyEligibility::AGING_KEY_MIN_API_MULTIPLIER)->toBe(1.2);
        });
    });
});
