<?php

use App\Domain\Games\GameNameNormalizer;
use App\Domain\Games\GiveawayPolicy;
use App\Services\Games\GiveawayRepository;
use Illuminate\Support\Facades\DB;
use Tests\Support\GiveawayFactory;

function linkGiveawayToGame(int $giveawayId, string $name, ?string $region = null): int
{
    $gameId = DB::table('games')->insertGetId([
        'name' => $name,
        'normalized_name' => GameNameNormalizer::normalize($name),
        'region' => $region,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('giveaway_games')->insert(['giveaway_id' => $giveawayId, 'game_id' => $gameId]);

    return $gameId;
}

describe('GiveawayRepository::forListing()', function () {

    it('presents an active giveaway as flagged with no deadline', function () {
        GiveawayFactory::active('Dwarven Realms');

        $giveaway = app(GiveawayRepository::class)->forListing()[0];

        expect($giveaway)->toMatchArray([
            'title' => 'Dwarven Realms (Steam) Key Giveaway',
            'game_name' => 'Dwarven Realms',
            'store' => 'Steam',
            'status' => 'active',
            'is_flagged' => true,
            'flagged_until' => null,
            'end_date' => null,
            'games' => [],
        ]);
    });

    it('presents when an ended giveaway stops counting', function () {
        GiveawayFactory::ended('Leaper', durationDays: 7, endedDaysAgo: 10);

        $giveaway = app(GiveawayRepository::class)->forListing()[0];

        expect($giveaway['status'])->toBe('ended')
            ->and($giveaway['duration_days'])->toBe(7)
            ->and($giveaway['is_flagged'])->toBeTrue()
            ->and($giveaway['flagged_until'])->toBe(now()->subDays(10)->addDays(GiveawayPolicy::SHORT_WINDOW_DAYS)->format('d/m/Y'));
    });

    it('keeps a giveaway past its window, marked as not flagged', function () {
        GiveawayFactory::ended('Leaper', durationDays: 7, endedDaysAgo: GiveawayPolicy::SHORT_WINDOW_DAYS + 1);

        expect(app(GiveawayRepository::class)->forListing()[0]['is_flagged'])->toBeFalse();
    });

    it('lists the games of the system the giveaway reaches, one per region', function () {
        $id = GiveawayFactory::active('Ballionaire');
        $global = linkGiveawayToGame($id, 'Ballionaire');
        $eu = linkGiveawayToGame($id, 'Ballionaire', 'EU');

        expect(app(GiveawayRepository::class)->forListing()[0]['games'])->toEqualCanonicalizing([
            ['id' => $global, 'name' => 'Ballionaire', 'region' => null],
            ['id' => $eu, 'name' => 'Ballionaire', 'region' => 'EU'],
        ]);
    });

    it('lists the most recently published first', function () {
        GiveawayFactory::ended('Older', durationDays: 7, endedDaysAgo: 30);
        GiveawayFactory::active('Newer');

        expect(array_column(app(GiveawayRepository::class)->forListing(), 'game_name'))->toBe(['Newer', 'Older']);
    });
});
