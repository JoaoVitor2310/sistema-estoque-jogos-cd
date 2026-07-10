<?php

use App\Domain\Games\GameNameNormalizer;

describe('GameNameNormalizer', function () {

    it('converts roman numerals to decimal', function () {
        expect(GameNameNormalizer::normalize('Grand Theft Auto V'))->toBe('grand theft auto 5');
    });

    it('removes punctuation and articles', function () {
        expect(GameNameNormalizer::normalize('The Witcher III: Wild Hunt'))->toBe('witcher 3 wild hunt');
    });

    it('lowercases casing differences', function () {
        expect(GameNameNormalizer::normalize('DOOM Eternal'))->toBe('doom eternal');
    });

    it('removes punctuation and articles together', function () {
        expect(GameNameNormalizer::normalize('Halo: The Master Chief Collection'))->toBe('halo master chief collection');
    });

    it('preserves edition suffixes — different product from the base game', function () {
        expect(GameNameNormalizer::normalize('Cyberpunk 2077 Deluxe Edition'))->toBe('cyberpunk 2077 deluxe edition');
    });

    it('preserves DLC suffixes — different product from the base game', function () {
        expect(GameNameNormalizer::normalize('DOOM Eternal: The Ancient Gods DLC'))->toBe('doom eternal ancient gods dlc');
    });

    it('preserves season pass suffixes', function () {
        expect(GameNameNormalizer::normalize('Fallout 4 Season Pass'))->toBe('fallout 4 season pass');
    });

    it('does not corrupt trademark and registered symbols (multibyte-safe)', function () {
        expect(GameNameNormalizer::normalize('Pathfinder: Kingmaker™ Enhanced Plus Edition®'))
            ->toBe('pathfinder kingmaker enhanced plus edition');
    });

    it('matches the same normalized value regardless of formatting differences', function () {
        expect(GameNameNormalizer::normalize('The Witcher III'))
            ->toBe(GameNameNormalizer::normalize('Witcher 3'));
    });

    it('trims and collapses extra whitespace', function () {
        expect(GameNameNormalizer::normalize('  Portal   2  '))->toBe('portal 2');
    });
});
