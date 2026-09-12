<?php

use App\Domain\Games\GameNameNormalizer;
use App\UseCases\Trades\StoreListTradeUseCase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BundleFactory;

function listTradeGames(array $overrides = []): array
{
    return array_merge([
        ['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => 'EU'],
    ], $overrides);
}

describe('StoreListTradeUseCase', function () {

    it('creates a trade with one persisted line per researched game', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => 'EU']],
        ]);

        $line = $trade->lines->first();

        expect($trade->lines)->toHaveCount(1)
            ->and($line->game_name)->toBe('Half-Life')
            ->and($line->market_price)->toBe('4.50')
            ->and($line->popularity)->toBe(500)
            ->and($line->region)->toBe('EU')
            ->and($line->bundle)->toBeNull()
            ->and($line->expires_at)->toBeNull()
            ->and($line->key_code)->toBeNull()
            ->and($line->position)->toBe(0);
    });

    it('keeps the researched order in the line positions', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [
                ['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => null],
                ['name' => 'Portal', 'price_euro' => 2.00, 'popularity' => 100, 'region' => null],
            ],
        ]);

        expect($trade->lines->pluck('game_name')->all())->toBe(['Half-Life', 'Portal'])
            ->and($trade->lines->pluck('position')->all())->toBe([0, 1]);
    });

    it('sets date to today', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => listTradeGames(),
        ]);

        expect($trade->date->toDateString())->toBe(now()->toDateString());
    });

    it('is born with a delivery credential', function () {
        // Sem ela a trade chega na aba sem link e sem código para copiar.
        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => listTradeGames(),
        ]);

        expect($trade->delivery_uuid)->not->toBeNull()
            ->and($trade->delivery_token)->not->toBeNull();
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

        expect($trade->lines->first()->market_price)->toBe('10.00');
    });

    it('handles null region as a null region column', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [['name' => 'Portal', 'price_euro' => 3.00, 'popularity' => 100, 'region' => null]],
        ]);

        expect($trade->lines->first()->region)->toBeNull();
    });

    it('stores gamivo_id when provided', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => null, 'gamivo_id' => '144601']],
        ]);

        expect($trade->lines->first()->gamivo_id)->toBe('144601');
    });

    it('stores null gamivo_id when not provided', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => listTradeGames(),
        ]);

        expect($trade->lines->first()->gamivo_id)->toBeNull();
    });
});

describe('StoreListTradeUseCase — bundle lookup', function () {

    it('fills bundle name when game is in a bundle released within 3 months', function () {
        BundleFactory::withGame('Stardew Valley', 'Humble Choice Junho 2026', now()->subMonths(1)->toDateString());

        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [['name' => 'Stardew Valley', 'price_euro' => 5.00, 'popularity' => 1000, 'region' => null]],
        ]);

        expect($trade->lines->first()->bundle)->toBe('Humble Choice Junho 2026');
    });

    it('leaves bundle null when game has no bundle', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [['name' => 'Game Without Bundle', 'price_euro' => 5.00, 'popularity' => 100, 'region' => null]],
        ]);

        expect($trade->lines->first()->bundle)->toBeNull();
    });

    it('leaves bundle null when game bundle was released more than 3 months ago', function () {
        BundleFactory::withGame('Old Game', 'Humble Bundle Antigo', now()->subMonths(4)->toDateString());

        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [['name' => 'Old Game', 'price_euro' => 3.00, 'popularity' => 50, 'region' => null]],
        ]);

        expect($trade->lines->first()->bundle)->toBeNull();
    });

    it('resolves bundle independently per game in a multi-game payload', function () {
        BundleFactory::withGame('Hollow Knight', 'Indie Bundle', now()->subMonths(2)->toDateString());

        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [
                ['name' => 'Hollow Knight', 'price_euro' => 4.00, 'popularity' => 800, 'region' => null],
                ['name' => 'Unknown Game', 'price_euro' => 6.00, 'popularity' => 200, 'region' => null],
            ],
        ]);

        expect($trade->lines[0]->bundle)->toBe('Indie Bundle')
            ->and($trade->lines[1]->bundle)->toBeNull();
    });

    it('matches game name case-insensitively', function () {
        BundleFactory::withGame('hollow knight', 'Indie Bundle', now()->subMonths(1)->toDateString());

        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [['name' => 'Hollow Knight', 'price_euro' => 4.00, 'popularity' => 800, 'region' => null]],
        ]);

        expect($trade->lines->first()->bundle)->toBe('Indie Bundle');
    });

    it('matches game name regardless of roman numeral vs decimal formatting', function () {
        BundleFactory::withGame('The Witcher III', 'RPG Bundle', now()->subMonths(1)->toDateString());

        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [['name' => 'Witcher 3', 'price_euro' => 4.00, 'popularity' => 800, 'region' => null]],
        ]);

        expect($trade->lines->first()->bundle)->toBe('RPG Bundle');
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

        expect($trade->lines->first()->bundle)->toBe('Bundle Recente');
    });
});

describe('StoreListTradeUseCase — bundle named by the title', function () {

    // A pesquisa disparada de um bundle devolve o nome dele no `title`. Saber
    // de que bundle o jogo veio vence o palpite por nome + recência, que erra
    // justamente onde esta pesquisa é mais usada.

    it('attributes every line to the bundle the title names', function () {
        BundleFactory::withGame('Taiji', 'Humble Perplexing Puzzles Bundle', now()->subMonths(1)->toDateString());

        $trade = app(StoreListTradeUseCase::class)->execute([
            'title' => 'Humble Perplexing Puzzles Bundle',
            'games' => [
                ['name' => 'Taiji', 'price_euro' => 1.40, 'popularity' => 100, 'region' => null],
                // Jogo do mesmo bundle que o AllKeyShop devolveu com outro
                // nome: o lookup por nome não o alcançaria.
                ['name' => 'Viewfinder: Director Cut', 'price_euro' => 5.72, 'popularity' => 300, 'region' => null],
            ],
        ]);

        expect($trade->lines[0]->bundle)->toBe('Humble Perplexing Puzzles Bundle')
            ->and($trade->lines[1]->bundle)->toBe('Humble Perplexing Puzzles Bundle');
    });

    it('attributes by title even when the bundle is older than the recent window', function () {
        // O caso que motivou a correção: bundle fora da janela de 3 meses
        // devolvia toda linha com bundle null, mesmo o callback trazendo o
        // nome exato do bundle.
        BundleFactory::withGame('Taiji', 'Bundle Antigo', now()->subMonths(6)->toDateString());

        $trade = app(StoreListTradeUseCase::class)->execute([
            'title' => 'Bundle Antigo',
            'games' => [['name' => 'Taiji', 'price_euro' => 1.40, 'popularity' => 100, 'region' => null]],
        ]);

        expect($trade->lines->first()->bundle)->toBe('Bundle Antigo');
    });

    it('ignores a title that names no bundle', function () {
        BundleFactory::withGame('Stardew Valley', 'Indie Bundle', now()->subMonths(1)->toDateString());

        $trade = app(StoreListTradeUseCase::class)->execute([
            'title' => 'Lista qualquer do supplier',
            'games' => [['name' => 'Stardew Valley', 'price_euro' => 5.00, 'popularity' => 100, 'region' => null]],
        ]);

        // Cai no palpite por nome, que aqui acerta.
        expect($trade->lines->first()->bundle)->toBe('Indie Bundle');
    });

    it('does not attribute by title when the trade has a supplier', function () {
        // Lista comentada também manda `title` — e ali ele é o nome da lista no
        // SteamTrades. Lista chamada como um bundle não faz de todo jogo dela
        // um jogo daquele bundle.
        BundleFactory::withGame('Taiji', 'Humble Choice', now()->subMonths(1)->toDateString());

        $trade = app(StoreListTradeUseCase::class)->execute([
            'supplier_steam_id' => '76561198012345678',
            'title' => 'Humble Choice',
            'games' => [['name' => 'Jogo Fora De Bundle', 'price_euro' => 5.00, 'popularity' => 100, 'region' => null]],
        ]);

        expect($trade->lines->first()->bundle)->toBeNull();
    });

    it('ignores a title naming a soft-deleted bundle', function () {
        BundleFactory::withGame('Taiji', 'Bundle Apagado', now()->subMonths(1)->toDateString());
        DB::table('bundles')->where('name', 'Bundle Apagado')->update(['deleted_at' => now()]);

        $trade = app(StoreListTradeUseCase::class)->execute([
            'title' => 'Bundle Apagado',
            'games' => [['name' => 'Taiji', 'price_euro' => 1.40, 'popularity' => 100, 'region' => null]],
        ]);

        expect($trade->lines->first()->bundle)->toBeNull();
    });
});
