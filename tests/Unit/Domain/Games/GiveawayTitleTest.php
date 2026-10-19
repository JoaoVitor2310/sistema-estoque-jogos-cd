<?php

use App\Domain\Games\GiveawayTitle;

describe('GiveawayTitle::gameName()', function () {

    it('extracts the game from the formats seen in the GamerPower feed', function (string $title, string $game) {
        expect(GiveawayTitle::gameName($title))->toBe($game);
    })->with([
        'steam key' => ['Dwarven Realms (Steam) Key Giveaway', 'Dwarven Realms'],
        'steam, no key' => ['Leaper (Steam) Giveaway', 'Leaper'],
        'store, no key' => ['Leaper (IndieGala) Giveaway', 'Leaper'],
        'colon in the name' => ['Agent A: A puzzle in disguise (Epic Games) Giveaway', 'Agent A: A puzzle in disguise'],
        'no store' => ['Dwarven Realms Key Giveaway', 'Dwarven Realms'],
        'lowercase suffix' => ['Leaper (IndieGala) giveaway', 'Leaper'],
    ]);

    it('keeps a parenthesis that belongs to the name and cuts only the last one', function () {
        expect(GiveawayTitle::gameName('Game (2013) (Steam) Giveaway'))->toBe('Game (2013)');
    });

    it('returns null for a title outside the pattern', function (string $title) {
        expect(GiveawayTitle::gameName($title))->toBeNull();
    })->with([
        'no giveaway suffix' => ['Mystery Free Weekend'],
        'suffix only' => ['(Steam) Key Giveaway'],
        'empty' => [''],
    ]);
});

describe('GiveawayTitle::store()', function () {

    it('returns what was in the last parenthesis', function () {
        expect(GiveawayTitle::store('Dwarven Realms (Steam) Key Giveaway'))->toBe('Steam')
            ->and(GiveawayTitle::store('Game (2013) (IndieGala) Giveaway'))->toBe('IndieGala');
    });

    it('returns null when the title has no store', function () {
        expect(GiveawayTitle::store('Dwarven Realms Key Giveaway'))->toBeNull()
            ->and(GiveawayTitle::store('Mystery Free Weekend'))->toBeNull();
    });
});
