<?php

use App\Domain\Games\GiveawayPolicy;
use App\Services\Games\GiveawayService;
use Illuminate\Support\Facades\DB;
use Tests\Support\GiveawayFactory;

describe('GiveawayService::isGiveaway()', function () {

    it('flags a game whose giveaway is still active', function () {
        GiveawayFactory::active('Dwarven Realms');

        expect(app(GiveawayService::class)->isGiveaway('Dwarven Realms'))->toBeTrue();
    });

    it('flags a game whose giveaway ended but is still in the window', function () {
        GiveawayFactory::ended('Dwarven Realms', durationDays: 7, endedDaysAgo: GiveawayPolicy::SHORT_WINDOW_DAYS - 1);

        expect(app(GiveawayService::class)->isGiveaway('Dwarven Realms'))->toBeTrue();
    });

    it('does not flag a game whose giveaway window is over', function () {
        GiveawayFactory::ended('Dwarven Realms', durationDays: 7, endedDaysAgo: GiveawayPolicy::SHORT_WINDOW_DAYS + 1);

        expect(app(GiveawayService::class)->isGiveaway('Dwarven Realms'))->toBeFalse();
    });

    it('uses the longer window of a longer giveaway', function () {
        // Mesmo tempo desde o fim do caso acima, mas durou mais: ainda conta.
        GiveawayFactory::ended('Dwarven Realms', durationDays: 45, endedDaysAgo: GiveawayPolicy::SHORT_WINDOW_DAYS + 1);

        expect(app(GiveawayService::class)->isGiveaway('Dwarven Realms'))->toBeTrue();
    });

    it('flags when any of several giveaways of the same game is in the window', function () {
        GiveawayFactory::ended('Dwarven Realms', durationDays: 7, endedDaysAgo: 400);
        GiveawayFactory::ended('Dwarven Realms', durationDays: 7, endedDaysAgo: 10);

        expect(app(GiveawayService::class)->isGiveaway('Dwarven Realms'))->toBeTrue();
    });

    it('matches by normalized name, not by the exact spelling', function () {
        GiveawayFactory::active('Alien Shooter 2: Reloaded');

        expect(app(GiveawayService::class)->isGiveaway('alien shooter 2 reloaded'))->toBeTrue();
    });

    it('does not flag an edition the giveaway is not about', function () {
        GiveawayFactory::active('Ballionaire');

        expect(app(GiveawayService::class)->isGiveaway('Ballionaire Deluxe Edition'))->toBeFalse();
    });

    it('does not need the game to exist in games', function () {
        GiveawayFactory::active('Dwarven Realms');

        expect(DB::table('games')->count())->toBe(0)
            ->and(app(GiveawayService::class)->isGiveaway('Dwarven Realms'))->toBeTrue();
    });

    it('does not flag a line without a game', function () {
        GiveawayFactory::active('Dwarven Realms');

        expect(app(GiveawayService::class)->isGiveaway(null))->toBeFalse()
            ->and(app(GiveawayService::class)->isGiveaway(''))->toBeFalse();
    });
});

describe('GiveawayService::markResearched()', function () {

    it('marks each researched game, keeping its fields', function () {
        GiveawayFactory::active('Dwarven Realms');

        $marked = app(GiveawayService::class)->markResearched([
            ['name' => 'Dwarven Realms', 'region' => 'EU'],
            ['name' => 'Portal', 'region' => null],
        ]);

        expect($marked)->toBe([
            ['name' => 'Dwarven Realms', 'region' => 'EU', 'is_giveaway' => true],
            ['name' => 'Portal', 'region' => null, 'is_giveaway' => false],
        ]);
    });

    it('answers the whole batch with one query', function () {
        GiveawayFactory::active('Dwarven Realms');
        DB::enableQueryLog();

        app(GiveawayService::class)->markResearched([['name' => 'Dwarven Realms'], ['name' => 'Portal'], ['name' => 'Leaper']]);

        expect(DB::getQueryLog())->toHaveCount(1);
    });

    it('marks a game without a name as not in giveaway', function () {
        expect(app(GiveawayService::class)->markResearched([['name' => '']]))->toBe([['name' => '', 'is_giveaway' => false]]);
    });
});
