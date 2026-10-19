<?php

/*
|--------------------------------------------------------------------------
| SyncGiveawaysUseCase — integration
|--------------------------------------------------------------------------
|
| O feed do GamerPower é interceptado por Http::fake(). Cada passada recebe
| o `$now` explícito, para o tempo andar entre uma sincronização e outra.
|
*/

use App\Domain\Enums\GiveawayStatus;
use App\Domain\Games\GameNameNormalizer;
use App\Domain\Games\GiveawayPolicy;
use App\Models\Giveaway;
use App\UseCases\Games\SyncGiveawaysUseCase;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Um item do feed, no formato da API. */
function gamerPowerItem(int $id, string $title, array $overrides = []): array
{
    return $overrides + [
        'id' => $id,
        'title' => $title,
        'worth' => '$9.99',
        'published_date' => '2026-10-01 10:00:00',
        'type' => 'Game',
        'platforms' => 'PC, Steam',
        'end_date' => 'N/A',
        'status' => 'Active',
        'gamerpower_url' => 'https://www.gamerpower.com/giveaway-'.$id,
    ];
}

/**
 * Uma resposta do feed por passada — um fake só, porque fakes registrados
 * depois não substituem os anteriores.
 */
function fakeGamerPowerFeeds(array ...$responses): void
{
    $sequence = Http::sequence();

    foreach ($responses as $response) {
        $response === []
            ? $sequence->push(['status' => 0], 201)
            : $sequence->push($response, 200);
    }

    Http::fake(['www.gamerpower.com/*' => $sequence]);
}

function insertGameForGiveawaySync(string $name, ?string $region = null): int
{
    return DB::table('games')->insertGetId([
        'name' => $name,
        'normalized_name' => GameNameNormalizer::normalize($name),
        'region' => $region,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function syncGiveawaysAt(string $now): void
{
    app(SyncGiveawaysUseCase::class)->execute(Carbon::parse($now));
}

/** @return list<int> */
function linkedGameIds(int $gamerpowerId): array
{
    return Giveaway::where('gamerpower_id', $gamerpowerId)->firstOrFail()
        ->games()->orderBy('games.id')->pluck('games.id')->all();
}

describe('SyncGiveawaysUseCase', function () {

    it('records a new giveaway with the game extracted from the title', function () {
        fakeGamerPowerFeeds([gamerPowerItem(3716, 'Dwarven Realms (Steam) Key Giveaway')]);

        syncGiveawaysAt('2026-10-18 12:00:00');

        $giveaway = Giveaway::sole();
        expect($giveaway->gamerpower_id)->toBe(3716)
            ->and($giveaway->title)->toBe('Dwarven Realms (Steam) Key Giveaway')
            ->and($giveaway->game_name)->toBe('Dwarven Realms')
            ->and($giveaway->normalized_game_name)->toBe(GameNameNormalizer::normalize('Dwarven Realms'))
            ->and($giveaway->store)->toBe('Steam')
            ->and($giveaway->worth)->toBe('9.99')
            ->and($giveaway->published_date->toDateTimeString())->toBe('2026-10-01 10:00:00')
            ->and($giveaway->end_date)->toBeNull()
            ->and($giveaway->status)->toBe(GiveawayStatus::Active)
            ->and($giveaway->last_seen_at->toDateTimeString())->toBe('2026-10-18 12:00:00');
    });

    it('updates the same giveaway on the next pass instead of duplicating it', function () {
        fakeGamerPowerFeeds(
            [gamerPowerItem(3716, 'Dwarven Realms (Steam) Key Giveaway')],
            [gamerPowerItem(3716, 'Dwarven Realms (Steam) Key Giveaway', ['end_date' => '2026-10-31 23:59:59'])],
        );

        syncGiveawaysAt('2026-10-18 12:00:00');
        syncGiveawaysAt('2026-10-18 12:30:00');

        $giveaway = Giveaway::sole();
        expect($giveaway->end_date->toDateTimeString())->toBe('2026-10-31 23:59:59')
            ->and($giveaway->last_seen_at->toDateTimeString())->toBe('2026-10-18 12:30:00');
    });

    it('ends a giveaway that left the feed and keeps the last time it was seen', function () {
        fakeGamerPowerFeeds(
            [gamerPowerItem(1, 'Leaper (Steam) Giveaway'), gamerPowerItem(2, 'Dwarven Realms (Steam) Key Giveaway')],
            [gamerPowerItem(2, 'Dwarven Realms (Steam) Key Giveaway')],
        );

        syncGiveawaysAt('2026-10-18 12:00:00');
        syncGiveawaysAt('2026-10-18 12:30:00');

        $ended = Giveaway::where('gamerpower_id', 1)->sole();
        expect($ended->status)->toBe(GiveawayStatus::Ended)
            ->and($ended->last_seen_at->toDateTimeString())->toBe('2026-10-18 12:00:00')
            ->and(Giveaway::where('gamerpower_id', 2)->sole()->status)->toBe(GiveawayStatus::Active);
    });

    it('ends every active giveaway when the API answers that none is active', function () {
        fakeGamerPowerFeeds([gamerPowerItem(1, 'Leaper (Steam) Giveaway')], []);

        syncGiveawaysAt('2026-10-18 12:00:00');
        syncGiveawaysAt('2026-10-18 12:30:00');

        expect(Giveaway::sole()->status)->toBe(GiveawayStatus::Ended);
    });

    it('changes nothing when the API request fails', function () {
        $sequence = Http::sequence()
            ->push([gamerPowerItem(1, 'Leaper (Steam) Giveaway')], 200)
            ->push('Server error', 500)
            ->pushFailedConnection();
        Http::fake(['www.gamerpower.com/*' => $sequence]);

        syncGiveawaysAt('2026-10-18 12:00:00');
        syncGiveawaysAt('2026-10-18 12:30:00');
        syncGiveawaysAt('2026-10-18 13:00:00');

        $giveaway = Giveaway::sole();
        expect($giveaway->status)->toBe(GiveawayStatus::Active)
            ->and($giveaway->last_seen_at->toDateTimeString())->toBe('2026-10-18 12:00:00');
    });

    it('reactivates an ended giveaway that comes back to the feed', function () {
        fakeGamerPowerFeeds(
            [gamerPowerItem(1, 'Leaper (Steam) Giveaway')],
            [],
            [gamerPowerItem(1, 'Leaper (Steam) Giveaway')],
        );

        syncGiveawaysAt('2026-10-18 12:00:00');
        syncGiveawaysAt('2026-10-18 12:30:00');
        syncGiveawaysAt('2026-10-18 13:00:00');

        $giveaway = Giveaway::sole();
        expect($giveaway->status)->toBe(GiveawayStatus::Active)
            ->and($giveaway->last_seen_at->toDateTimeString())->toBe('2026-10-18 13:00:00');
    });

    it('links the giveaway to the game in every region', function () {
        $global = insertGameForGiveawaySync('Ballionaire');
        $eu = insertGameForGiveawaySync('Ballionaire', 'EU');
        $row = insertGameForGiveawaySync('Ballionaire', 'ROW');
        insertGameForGiveawaySync('Ballionaire Deluxe Edition');
        fakeGamerPowerFeeds([gamerPowerItem(1, 'Ballionaire (Steam) Key Giveaway')]);

        syncGiveawaysAt('2026-10-18 12:00:00');

        expect(linkedGameIds(1))->toBe([$global, $eu, $row]);
    });

    it('matches the game by normalized name, not by the exact spelling', function () {
        $game = insertGameForGiveawaySync('Alien Shooter 2 Reloaded');
        fakeGamerPowerFeeds([gamerPowerItem(1, 'Alien Shooter 2: Reloaded (Steam) Key Giveaway')]);

        syncGiveawaysAt('2026-10-18 12:00:00');

        expect(linkedGameIds(1))->toBe([$game]);
    });

    it('does not create a game for a giveaway of a game we never had', function () {
        fakeGamerPowerFeeds([gamerPowerItem(1, 'Dwarven Realms (Steam) Key Giveaway')]);

        syncGiveawaysAt('2026-10-18 12:00:00');

        expect(DB::table('games')->count())->toBe(0)
            ->and(linkedGameIds(1))->toBe([]);
    });

    it('links a region registered after the giveaway on the next pass', function () {
        $global = insertGameForGiveawaySync('Ballionaire');
        fakeGamerPowerFeeds([gamerPowerItem(1, 'Ballionaire (Steam) Key Giveaway')], []);
        syncGiveawaysAt('2026-10-18 12:00:00');

        $eu = insertGameForGiveawaySync('Ballionaire', 'EU');
        // Já encerrado, mas dentro da janela: continua sendo revinculado.
        syncGiveawaysAt('2026-10-25 12:00:00');

        expect(linkedGameIds(1))->toBe([$global, $eu]);
    });

    it('unlinks a game renamed to something else', function () {
        $game = insertGameForGiveawaySync('Ballionaire');
        fakeGamerPowerFeeds(
            [gamerPowerItem(1, 'Ballionaire (Steam) Key Giveaway')],
            [gamerPowerItem(1, 'Ballionaire (Steam) Key Giveaway')],
        );
        syncGiveawaysAt('2026-10-18 12:00:00');

        DB::table('games')->where('id', $game)->update([
            'name' => 'Ballionaire Deluxe Edition',
            'normalized_name' => GameNameNormalizer::normalize('Ballionaire Deluxe Edition'),
        ]);
        syncGiveawaysAt('2026-10-18 12:30:00');

        expect(linkedGameIds(1))->toBe([]);
    });

    it('freezes the links of a giveaway past its window', function () {
        $global = insertGameForGiveawaySync('Leaper');
        fakeGamerPowerFeeds(
            [gamerPowerItem(1, 'Leaper (Steam) Giveaway', ['published_date' => '2026-01-01 10:00:00'])],
            [],
            [],
        );
        // Durou 7 dias: fica na janela curta depois de encerrar.
        syncGiveawaysAt('2026-01-08 10:00:00');
        syncGiveawaysAt('2026-01-08 10:30:00');

        insertGameForGiveawaySync('Leaper', 'EU');
        $pastWindow = Carbon::parse('2026-01-08 10:00:00')->addDays(GiveawayPolicy::SHORT_WINDOW_DAYS + 1);
        syncGiveawaysAt($pastWindow->toDateTimeString());

        expect(linkedGameIds(1))->toBe([$global]);
    });

    it('saves a giveaway whose title is outside the pattern, without a game', function () {
        insertGameForGiveawaySync('Mystery Free Weekend');
        fakeGamerPowerFeeds([gamerPowerItem(1, 'Mystery Free Weekend')]);

        syncGiveawaysAt('2026-10-18 12:00:00');

        $giveaway = Giveaway::sole();
        expect($giveaway->game_name)->toBeNull()
            ->and($giveaway->normalized_game_name)->toBeNull()
            ->and(linkedGameIds(1))->toBe([]);
    });

    it('falls back to the first time it was seen when the API has no publication date', function () {
        fakeGamerPowerFeeds([gamerPowerItem(1, 'Leaper (Steam) Giveaway', ['published_date' => 'N/A'])]);

        syncGiveawaysAt('2026-10-18 12:00:00');

        expect(Giveaway::sole()->published_date->toDateTimeString())->toBe('2026-10-18 12:00:00');
    });
});
