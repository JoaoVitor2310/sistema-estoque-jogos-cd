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
        'bare steam, key' => ['HELLCARD Steam Key Giveaway', 'HELLCARD'],
        'bare steam, no key' => ['HELLCARD Steam Giveaway', 'HELLCARD'],
        'bare steam after a multi-word name' => ['Everwind AW-51 Pack Steam Key Giveaway', 'Everwind AW-51 Pack'],
    ]);

    it('keeps steam inside the name when it is not the last word before the suffix', function () {
        expect(GiveawayTitle::gameName('Steam Marines Key Giveaway'))->toBe('Steam Marines');
    });

    it('does not cut a bare word other than steam', function () {
        // Só "Steam" é origem sem parêntese: outra palavra solta é parte do nome
        expect(GiveawayTitle::gameName('Leaper IndieGala Giveaway'))->toBe('Leaper IndieGala');
    });

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

    it('returns steam when it comes without a parenthesis', function () {
        expect(GiveawayTitle::store('HELLCARD Steam Key Giveaway'))->toBe('Steam')
            ->and(GiveawayTitle::store('HELLCARD steam Giveaway'))->toBe('Steam');
    });

    it('returns null when the title has no store', function () {
        expect(GiveawayTitle::store('Dwarven Realms Key Giveaway'))->toBeNull()
            ->and(GiveawayTitle::store('Mystery Free Weekend'))->toBeNull();
    });
});
