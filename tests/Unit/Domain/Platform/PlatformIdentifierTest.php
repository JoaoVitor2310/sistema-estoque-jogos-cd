<?php

/*
|--------------------------------------------------------------------------
| PlatformIdentifier — unit tests
|--------------------------------------------------------------------------
|
| PHP puro — sem DB, sem bootstrap do framework.
| Documenta o comportamento exato de cada padrão e a ordem de prioridade.
|
*/

use App\Domain\Platform\PlatformIdentifier;

describe('PlatformIdentifier', function () {

    describe('identify()', function () {

        // ── Steam ─────────────────────────────────────────────────────────

        it('identifies a standard Steam key (XXXXX-XXXXX-XXXXX)', function () {
            expect(PlatformIdentifier::identify('ABCDE-12345-FGHIJ'))->toBe('Steam');
        });

        it('identifies an extended Steam key (15 chars + space + 2 chars)', function () {
            expect(PlatformIdentifier::identify('ABCDE12345FGHIJ AB'))->toBe('Steam');
        });

        // ── EA ────────────────────────────────────────────────────────────

        it('identifies an EA key (XXXX-XXXX-XXXX-XXXX-XXXX)', function () {
            expect(PlatformIdentifier::identify('ABCD-1234-EFGH-5678-IJKL'))->toBe('EA');
        });

        // ── EA/Ubisoft ────────────────────────────────────────────────────

        it('identifies an EA/Ubisoft key (XXXX-XXXX-XXXX-XXXX)', function () {
            expect(PlatformIdentifier::identify('ABCD-1234-EFGH-5678'))->toBe('EA/Ubisoft');
        });

        // ── EGS ───────────────────────────────────────────────────────────

        it('identifies an Epic Games Store key (XXXXX-XXXXX-XXXXX-XXXXX)', function () {
            expect(PlatformIdentifier::identify('ABCDE-12345-FGHIJ-67890'))->toBe('EGS');
        });

        // ── GOG ───────────────────────────────────────────────────────────

        it('identifies a GOG key (18 consecutive word characters)', function () {
            expect(PlatformIdentifier::identify('ABCDE12345FGHIJ678'))->toBe('GOG');
        });

        // ── XBOX ──────────────────────────────────────────────────────────

        it('identifies an Xbox key (XXXXX-XXXXX-XXXXX-XXXXX-XXXXX)', function () {
            expect(PlatformIdentifier::identify('ABCDE-12345-FGHIJ-67890-KLMNO'))->toBe('XBOX');
        });

        // ── PSN ───────────────────────────────────────────────────────────

        it('identifies a PSN key (XXXX-XXXX-XXXX)', function () {
            expect(PlatformIdentifier::identify('ABCD-1234-EFGH'))->toBe('PSN');
        });

        // ── Unknown ───────────────────────────────────────────────────────

        it('returns DESCONHECIDO for an unrecognized format', function () {
            expect(PlatformIdentifier::identify('RANDOM-FORMAT-KEY-123'))->toBe('DESCONHECIDO');
        });

        it('returns DESCONHECIDO for an empty string', function () {
            expect(PlatformIdentifier::identify(''))->toBe('DESCONHECIDO');
        });

        it('returns DESCONHECIDO for a gift link (URL)', function () {
            // Gift links chegam com URL — nenhum padrão de plataforma bate
            expect(PlatformIdentifier::identify('https://store.steampowered.com/gift/XXXXXXXX'))
                ->toBe('DESCONHECIDO');
        });

        // ── Ordem de prioridade ───────────────────────────────────────────

        it('matches EA (5-segment) before EA/Ubisoft (4-segment) due to pattern order', function () {
            // XXXX-XXXX-XXXX-XXXX-XXXX deve ser EA, não EA/Ubisoft
            // (EA/Ubisoft nunca veria esse formato pois EA é verificado primeiro)
            expect(PlatformIdentifier::identify('ABCD-EFGH-IJKL-MNOP-QRST'))->toBe('EA');
        });
    });
});
