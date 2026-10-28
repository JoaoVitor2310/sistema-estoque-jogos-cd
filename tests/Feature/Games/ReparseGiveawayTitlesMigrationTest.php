<?php

/*
|--------------------------------------------------------------------------
| Migration 2026_10_28_000001_reparse_giveaway_titles — integration
|--------------------------------------------------------------------------
|
| Corrige os giveaways gravados antes de o parser conhecer a origem sem
| parêntese. A migration já rodou no setup da suíte: invocar a classe é o que
| exercita o up() sobre os dados semeados aqui.
|
*/

use App\Domain\Enums\GiveawayStatus;
use App\Models\Giveaway;
use App\Services\Games\GiveawayService;
use Illuminate\Support\Facades\DB;

function insertRawGiveaway(int $gamerpowerId, string $title, ?string $gameName, ?string $normalized, ?string $store): void
{
    DB::table('giveaways')->insert([
        'gamerpower_id' => $gamerpowerId,
        'title' => $title,
        'game_name' => $gameName,
        'normalized_game_name' => $normalized,
        'store' => $store,
        'gamerpower_url' => 'https://www.gamerpower.com/giveaway-'.$gamerpowerId,
        'published_date' => now()->subDays(3),
        'status' => GiveawayStatus::Ended->value,
        'last_seen_at' => now()->subDay(),
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subDays(3),
    ]);
}

function runReparseGiveawayTitlesMigration(): void
{
    $migration = require base_path('database/migrations/2026_10_28_000001_reparse_giveaway_titles.php');
    $migration->up();
}

it('fixes a giveaway saved with the bare steam store inside the game name', function () {
    insertRawGiveaway(1, 'HELLCARD Steam Key Giveaway', 'HELLCARD Steam', 'hellcard steam', null);

    runReparseGiveawayTitlesMigration();

    $giveaway = Giveaway::where('gamerpower_id', 1)->sole();
    expect($giveaway->game_name)->toBe('HELLCARD')
        ->and($giveaway->normalized_game_name)->toBe('hellcard')
        ->and($giveaway->store)->toBe('Steam');
});

it('lets a trade line with the plain game name be flagged as giveaway', function () {
    insertRawGiveaway(1, 'HELLCARD Steam Key Giveaway', 'HELLCARD Steam', 'hellcard steam', null);
    // Antes da correção, só o nome com "Steam" casava
    expect(app(GiveawayService::class)->isGiveaway('HELLCARD'))->toBeFalse();

    runReparseGiveawayTitlesMigration();

    expect(app(GiveawayService::class)->isGiveaway('HELLCARD'))->toBeTrue();
});

it('leaves a giveaway that was already parsed correctly untouched', function () {
    insertRawGiveaway(1, 'Dwarven Realms (Steam) Key Giveaway', 'Dwarven Realms', 'dwarven realms', 'Steam');
    $before = DB::table('giveaways')->where('gamerpower_id', 1)->value('updated_at');

    runReparseGiveawayTitlesMigration();

    expect(DB::table('giveaways')->where('gamerpower_id', 1)->value('updated_at'))->toBe($before);
});
