<?php

use App\Domain\Trades\LegacyTradeLine;

/*
 * As formas exercitadas aqui foram levantadas no dump de produção de
 * 2026-08-15 (988 trades, 10.705 linhas). Onde a produção não tem o caso
 * (vírgula decimal, data impossível), o teste fixa o comportamento defensivo
 * — o campo é texto livre na aba e nada impede a forma de aparecer.
 */

describe('LegacyTradeLine::toAttributes — canonical line', function () {

    it('converts a fully filled legacy entry', function () {
        $attributes = LegacyTradeLine::toAttributes([
            'name' => 'Half-Life',
            'marketPriceRaw' => '4.50',
            'popularity' => '500',
            'regionLock' => 'EU',
            'bundle' => 'Humble Choice',
            'expiry' => '02/06/2027',
            'keyCode' => 'ABC-123',
            'gamivoId' => '144601',
        ], 0);

        expect($attributes)->toBe([
            'position' => 0,
            'game_name' => 'Half-Life',
            'market_price' => '4.50',
            'popularity' => 500,
            'region' => 'EU',
            'bundle' => 'Humble Choice',
            'expires_at' => '2027-06-02',
            'key_code' => 'ABC-123',
            'gamivo_id' => '144601',
        ]);
    });

    it('keeps the position it was given', function () {
        expect(LegacyTradeLine::toAttributes(['name' => 'Portal'], 7)['position'])->toBe(7);
    });
});

describe('LegacyTradeLine::toAttributes — shapes measured in production', function () {

    it('converts an entry with no gamivoId key at all', function () {
        // 3.834 linhas: o campo nasceu depois delas.
        $attributes = LegacyTradeLine::toAttributes([
            'name' => 'Portal',
            'marketPriceRaw' => '2.81',
            'popularity' => '100',
            'regionLock' => null,
            'bundle' => null,
            'expiry' => null,
            'keyCode' => null,
        ], 0);

        expect($attributes['gamivo_id'])->toBeNull()
            ->and($attributes['game_name'])->toBe('Portal');
    });

    it('converts the entirely empty entry the tab creates', function () {
        // Exatamente 1 linha em produção — o emptyRow() do CreateTradeUseCase.
        $attributes = LegacyTradeLine::toAttributes([
            'name' => '',
            'marketPriceRaw' => '',
            'bundle' => '',
            'expiry' => '',
            'popularity' => '',
            'regionLock' => '',
            'keyCode' => '',
            'gamivoId' => '',
        ], 3);

        expect($attributes)->toBe([
            'position' => 3,
            'game_name' => null,
            'market_price' => null,
            'popularity' => null,
            'region' => null,
            'bundle' => null,
            'expires_at' => null,
            'key_code' => null,
            'gamivo_id' => null,
        ]);
    });

    it('converts a half-filled entry with null name but a price', function () {
        // 30 linhas em produção têm exatamente esta forma.
        $attributes = LegacyTradeLine::toAttributes([
            'name' => null,
            'marketPriceRaw' => '2.81',
            'bundle' => null,
            'expiry' => null,
            'popularity' => null,
            'regionLock' => null,
            'keyCode' => null,
        ], 0);

        expect($attributes['game_name'])->toBeNull()
            ->and($attributes['market_price'])->toBe('2.81')
            ->and($attributes['popularity'])->toBeNull();
    });

    it('converts dd/mm/yyyy expiry to an ISO date', function () {
        expect(LegacyTradeLine::toAttributes(['expiry' => '25/02/2027'], 0)['expires_at'])
            ->toBe('2027-02-25');
    });

    it('accepts a single-digit day and month', function () {
        expect(LegacyTradeLine::toAttributes(['expiry' => '2/6/2027'], 0)['expires_at'])
            ->toBe('2027-06-02');
    });

    it('reads popularity as an integer', function () {
        expect(LegacyTradeLine::toAttributes(['popularity' => '999999'], 0)['popularity'])
            ->toBe(999999);
    });
});

describe('LegacyTradeLine::toAttributes — nothing is discarded', function () {

    it('returns a line even for an entry with no keys at all', function () {
        $attributes = LegacyTradeLine::toAttributes([], 2);

        expect($attributes['position'])->toBe(2)
            ->and($attributes['game_name'])->toBeNull();
    });

    it('keeps the key code even when the rest of the line is blank', function () {
        expect(LegacyTradeLine::toAttributes(['keyCode' => 'XXX-999'], 0)['key_code'])
            ->toBe('XXX-999');
    });
});

describe('LegacyTradeLine::toAttributes — what does not fit the column becomes null', function () {

    it('nulls a non-numeric price instead of aborting', function () {
        expect(LegacyTradeLine::toAttributes(['marketPriceRaw' => 'grátis'], 0)['market_price'])
            ->toBeNull();
    });

    it('accepts a decimal comma, converting it to a period', function () {
        expect(LegacyTradeLine::toAttributes(['marketPriceRaw' => '4,50'], 0)['market_price'])
            ->toBe('4.50');
    });

    it('nulls a non-integer popularity', function () {
        expect(LegacyTradeLine::toAttributes(['popularity' => '1.5'], 0)['popularity'])->toBeNull();
        expect(LegacyTradeLine::toAttributes(['popularity' => 'muito'], 0)['popularity'])->toBeNull();
    });

    it('nulls an impossible date', function () {
        expect(LegacyTradeLine::toAttributes(['expiry' => '31/02/2027'], 0)['expires_at'])->toBeNull();
    });

    it('nulls an unrecognised date format', function () {
        expect(LegacyTradeLine::toAttributes(['expiry' => 'junho'], 0)['expires_at'])->toBeNull();
    });

    it('accepts an ISO date unchanged', function () {
        expect(LegacyTradeLine::toAttributes(['expiry' => '2027-06-02'], 0)['expires_at'])
            ->toBe('2027-06-02');
    });

    it('trims surrounding whitespace and treats a blank string as null', function () {
        $attributes = LegacyTradeLine::toAttributes([
            'name' => '  Portal  ',
            'keyCode' => '   ',
        ], 0);

        expect($attributes['game_name'])->toBe('Portal')
            ->and($attributes['key_code'])->toBeNull();
    });

    it('nulls a field holding an array instead of a scalar', function () {
        expect(LegacyTradeLine::toAttributes(['name' => ['Portal']], 0)['game_name'])->toBeNull();
    });
});
