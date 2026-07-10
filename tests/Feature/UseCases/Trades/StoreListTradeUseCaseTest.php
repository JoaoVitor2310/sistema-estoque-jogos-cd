<?php

use App\Domain\Games\GameNameNormalizer;
use App\UseCases\Trades\StoreListTradeUseCase;
use Illuminate\Support\Facades\DB;

function seedBundleWithGame(string $gameName, string $bundleName, string $releaseDate): void
{
    $now = now()->toDateTimeString();

    $gameId = DB::table('games')->insertGetId([
        'name' => $gameName,
        'normalized_name' => GameNameNormalizer::normalize($gameName),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $bundleId = DB::table('bundles')->insertGetId(['name' => $bundleName, 'release_date' => $releaseDate, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('bundle_games')->insert(['bundle_id' => $bundleId, 'game_id' => $gameId, 'created_at' => $now, 'updated_at' => $now]);
}

function listTradeGames(array $overrides = []): array
{
    return array_merge([
        ['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => 'EU'],
    ], $overrides);
}

describe('StoreListTradeUseCase', function () {

    it('creates a trade with correctly converted games', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => 'EU']],
        ]);

        $game = $trade->games[0];

        expect($game['name'])->toBe('Half-Life')
            ->and($game['marketPriceRaw'])->toBe('4.50')
            ->and($game['popularity'])->toBe('500')
            ->and($game['regionLock'])->toBe('EU')
            ->and($game['bundle'])->toBeNull()
            ->and($game['expiry'])->toBeNull()
            ->and($game['keyCode'])->toBeNull();
    });

    it('sets date to today', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => listTradeGames(),
        ]);

        expect($trade->date->toDateString())->toBe(now()->toDateString());
    });

    it('creates and links supplier when supplier_steam_id is provided', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'supplier_steam_id' => '76561198000000001',
            'games' => listTradeGames(),
        ]);

        $supplier = DB::table('suppliers')->where('steam_id', '76561198000000001')->first();
        expect($supplier)->not->toBeNull()
            ->and($supplier->url)->toBe('https://steamcommunity.com/profiles/76561198000000001')
            ->and($trade->supplier_id)->toBe($supplier->id);
    });

    it('does not duplicate supplier on repeated calls with same steam_id', function () {
        $execute = fn () => app(StoreListTradeUseCase::class)->execute([
            'supplier_steam_id' => '76561198000000001',
            'games' => listTradeGames(),
        ]);

        $execute();
        $execute();

        expect(DB::table('suppliers')->where('steam_id', '76561198000000001')->count())->toBe(1);
    });

    it('creates trade without supplier_id when supplier_steam_id is absent', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => listTradeGames(),
        ]);

        expect($trade->supplier_id)->toBeNull();
    });

    it('stores list_code when provided', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'list_code' => 'G0eXM',
            'games' => listTradeGames(),
        ]);

        expect($trade->list_code)->toBe('G0eXM');
    });

    it('stores null list_code when not provided', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => listTradeGames(),
        ]);

        expect($trade->list_code)->toBeNull();
    });

    it('formats price_euro with period and 2 decimal places', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [['name' => 'Portal', 'price_euro' => 10.0, 'popularity' => 100, 'region' => null]],
        ]);

        expect($trade->games[0]['marketPriceRaw'])->toBe('10.00');
    });

    it('handles null region as null regionLock', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [['name' => 'Portal', 'price_euro' => 3.00, 'popularity' => 100, 'region' => null]],
        ]);

        expect($trade->games[0]['regionLock'])->toBeNull();
    });
});

describe('StoreListTradeUseCase — bundle lookup', function () {

    it('fills bundle name when game is in a bundle released within 3 months', function () {
        seedBundleWithGame('Stardew Valley', 'Humble Choice Junho 2026', now()->subMonths(1)->toDateString());

        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [['name' => 'Stardew Valley', 'price_euro' => 5.00, 'popularity' => 1000, 'region' => null]],
        ]);

        expect($trade->games[0]['bundle'])->toBe('Humble Choice Junho 2026');
    });

    it('leaves bundle null when game has no bundle', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [['name' => 'Game Without Bundle', 'price_euro' => 5.00, 'popularity' => 100, 'region' => null]],
        ]);

        expect($trade->games[0]['bundle'])->toBeNull();
    });

    it('leaves bundle null when game bundle was released more than 3 months ago', function () {
        seedBundleWithGame('Old Game', 'Humble Bundle Antigo', now()->subMonths(4)->toDateString());

        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [['name' => 'Old Game', 'price_euro' => 3.00, 'popularity' => 50, 'region' => null]],
        ]);

        expect($trade->games[0]['bundle'])->toBeNull();
    });

    it('resolves bundle independently per game in a multi-game payload', function () {
        seedBundleWithGame('Hollow Knight', 'Indie Bundle', now()->subMonths(2)->toDateString());

        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [
                ['name' => 'Hollow Knight', 'price_euro' => 4.00, 'popularity' => 800, 'region' => null],
                ['name' => 'Unknown Game', 'price_euro' => 6.00, 'popularity' => 200, 'region' => null],
            ],
        ]);

        expect($trade->games[0]['bundle'])->toBe('Indie Bundle')
            ->and($trade->games[1]['bundle'])->toBeNull();
    });

    it('matches game name case-insensitively', function () {
        seedBundleWithGame('hollow knight', 'Indie Bundle', now()->subMonths(1)->toDateString());

        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [['name' => 'Hollow Knight', 'price_euro' => 4.00, 'popularity' => 800, 'region' => null]],
        ]);

        expect($trade->games[0]['bundle'])->toBe('Indie Bundle');
    });

    it('matches game name regardless of roman numeral vs decimal formatting', function () {
        seedBundleWithGame('The Witcher III', 'RPG Bundle', now()->subMonths(1)->toDateString());

        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [['name' => 'Witcher 3', 'price_euro' => 4.00, 'popularity' => 800, 'region' => null]],
        ]);

        expect($trade->games[0]['bundle'])->toBe('RPG Bundle');
    });

    it('uses the most recent bundle when game appears in two recent bundles', function () {
        $now = now()->toDateTimeString();
        $gameId = DB::table('games')->insertGetId([
            'name' => 'Multi Bundle Game',
            'normalized_name' => GameNameNormalizer::normalize('Multi Bundle Game'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $oldBundleId = DB::table('bundles')->insertGetId(['name' => 'Bundle Antigo', 'release_date' => now()->subMonths(2)->toDateString(), 'created_at' => $now, 'updated_at' => $now]);
        $newBundleId = DB::table('bundles')->insertGetId(['name' => 'Bundle Recente', 'release_date' => now()->subWeeks(2)->toDateString(), 'created_at' => $now, 'updated_at' => $now]);

        DB::table('bundle_games')->insert(['bundle_id' => $oldBundleId, 'game_id' => $gameId, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('bundle_games')->insert(['bundle_id' => $newBundleId, 'game_id' => $gameId, 'created_at' => $now, 'updated_at' => $now]);

        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [['name' => 'Multi Bundle Game', 'price_euro' => 5.00, 'popularity' => 300, 'region' => null]],
        ]);

        expect($trade->games[0]['bundle'])->toBe('Bundle Recente');
    });
});
