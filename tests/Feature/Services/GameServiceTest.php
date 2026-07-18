<?php

/*
|--------------------------------------------------------------------------
| GameService — characterization tests
|--------------------------------------------------------------------------
|
| Cobre as operações de infraestrutura de jogos:
|   - getIdGamivo: busca prioritária nas keys, fallback nos games
|   - fillIdGamivo: preenche gamivo_id nulo em game existente
|   - createGameIfDontExists: find-or-create case-insensitive
|
*/

use App\Domain\Games\GameNameNormalizer;
use App\Services\Games\GameService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedGameServiceFks(): void
{
    DB::table('suppliers')->insertOrIgnore(['id' => 1, 'url' => 'https://steamcommunity.com/id/seed']);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('GameService', function () {

    beforeEach(fn () => seedGameServiceFks());

    // ── getIdGamivo ───────────────────────────────────────────────────────────

    describe('getIdGamivo()', function () {

        it('finds gamivo_id from keys first (priority over games table)', function () {
            // Key tem um gamivo_id — deve ser retornado sem consultar games
            DB::table('keys')->insert([
                'game_name' => 'Priority Game',
                'key_code' => 'PRIO-KEY-001',
                'gamivo_id' => 'gam-from-key',
                'market_price' => 5.00,
                'individual_cost' => 2.00,
                'purchase_profit_percent' => 25.00,
                'supplier_url' => 'https://steamcommunity.com/id/seed',
                'supplier_id' => 1,
                'claim_type' => 'Nenhuma',
                'key_format' => 'RK',
                'sell_platform' => 'Gamivo',
                'region' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Game com id diferente — não deve ser retornado
            DB::table('games')->insert(['name' => 'Priority Game', 'region' => null, 'gamivo_id' => 'gam-from-game', 'created_at' => now(), 'updated_at' => now()]);

            $result = app(GameService::class)->getIdGamivo('Priority Game', null);

            expect($result)->toBe('gam-from-key');
        });

        it('falls back to the games table when no key has the gamivo_id', function () {
            DB::table('games')->insert(['name' => 'Fallback Game', 'region' => null, 'gamivo_id' => 'gam-fallback-001', 'created_at' => now(), 'updated_at' => now()]);

            $result = app(GameService::class)->getIdGamivo('Fallback Game', null);

            expect($result)->toBe('gam-fallback-001');
        });

        it('returns false when the gamivo_id is not found in either table', function () {
            $result = app(GameService::class)->getIdGamivo('Completely Unknown Game', null);

            expect($result)->toBeFalse();
        });

        it('is case-insensitive when matching the game name', function () {
            DB::table('games')->insert(['name' => 'case sensitive game', 'region' => null, 'gamivo_id' => 'gam-case-001', 'created_at' => now(), 'updated_at' => now()]);

            $result = app(GameService::class)->getIdGamivo('Case Sensitive Game', null);

            expect($result)->toBe('gam-case-001');
        });

        it('matches by region — same name in different regions are different games', function () {
            DB::table('games')->insert(['name' => 'Regional Game', 'region' => 'EU', 'gamivo_id' => 'gam-eu-001', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('games')->insert(['name' => 'Regional Game', 'region' => 'NA', 'gamivo_id' => 'gam-na-001', 'created_at' => now(), 'updated_at' => now()]);

            expect(app(GameService::class)->getIdGamivo('Regional Game', 'EU'))->toBe('gam-eu-001')
                ->and(app(GameService::class)->getIdGamivo('Regional Game', 'NA'))->toBe('gam-na-001');
        });
    });

    // ── fillIdGamivo ──────────────────────────────────────────────────────────

    describe('fillIdGamivo()', function () {

        it('fills gamivo_id on the game when it is currently null', function () {
            DB::table('games')->insert(['name' => 'Fill Me Game', 'region' => null, 'gamivo_id' => null, 'created_at' => now(), 'updated_at' => now()]);

            app(GameService::class)->fillIdGamivo('Fill Me Game', null, 'gam-new-id');

            $game = DB::table('games')->where('name', 'Fill Me Game')->first();
            expect($game->gamivo_id)->toBe('gam-new-id');
        });

        it('does not overwrite gamivo_id when it is already set', function () {
            DB::table('games')->insert(['name' => 'Already Has Id', 'region' => null, 'gamivo_id' => 'gam-original', 'created_at' => now(), 'updated_at' => now()]);

            app(GameService::class)->fillIdGamivo('Already Has Id', null, 'gam-should-not-overwrite');

            $game = DB::table('games')->where('name', 'Already Has Id')->first();
            expect($game->gamivo_id)->toBe('gam-original');
        });

        it('does nothing when the game is not found', function () {
            // Não deve lançar exceção — comportamento silencioso
            expect(fn () => app(GameService::class)->fillIdGamivo('Non Existent Game', null, 'gam-ghost'))
                ->not->toThrow(\Throwable::class);
        });
    });

    // ── createGameIfDontExists ────────────────────────────────────────────────

    describe('createGameIfDontExists()', function () {

        it('creates the game record when it does not exist', function () {
            app(GameService::class)->createGameIfDontExists([
                'game_name' => 'Brand New Game',
                'region' => null,
                'gamivo_id' => 'gam-new-001',
            ]);

            expect(DB::table('games')->where('name', 'Brand New Game')->exists())->toBeTrue();
        });

        it('stores gamivo_id on the created game', function () {
            app(GameService::class)->createGameIfDontExists([
                'game_name' => 'Game With Gamivo Id',
                'region' => null,
                'gamivo_id' => 'gam-stored-001',
            ]);

            $game = DB::table('games')->where('name', 'Game With Gamivo Id')->first();
            expect($game->gamivo_id)->toBe('gam-stored-001');
        });

        it('does not create a duplicate when the game already exists (case-insensitive)', function () {
            DB::table('games')->insert(['name' => 'existing game', 'region' => null, 'created_at' => now(), 'updated_at' => now()]);

            app(GameService::class)->createGameIfDontExists([
                'game_name' => 'Existing Game', // Casing diferente
                'region' => null,
                'gamivo_id' => null,
            ]);

            $count = DB::table('games')->whereRaw('LOWER("name") = ?', ['existing game'])->count();
            expect($count)->toBe(1);
        });

        it('treats games with the same name but different regions as distinct records', function () {
            app(GameService::class)->createGameIfDontExists(['game_name' => 'Multi Region Game', 'region' => 'EU', 'gamivo_id' => null]);
            app(GameService::class)->createGameIfDontExists(['game_name' => 'Multi Region Game', 'region' => 'NA', 'gamivo_id' => null]);

            $count = DB::table('games')->where('name', 'Multi Region Game')->count();
            expect($count)->toBe(2);
        });

        it('is idempotent — calling twice does not create duplicates', function () {
            $data = ['game_name' => 'Idempotent Game', 'region' => null, 'gamivo_id' => null];

            app(GameService::class)->createGameIfDontExists($data);
            app(GameService::class)->createGameIfDontExists($data);

            $count = DB::table('games')->where('name', 'Idempotent Game')->count();
            expect($count)->toBe(1);
        });

        it('fills normalized_name on the created game', function () {
            app(GameService::class)->createGameIfDontExists([
                'game_name' => 'The Witcher III',
                'region' => null,
                'gamivo_id' => null,
            ]);

            $game = DB::table('games')->where('name', 'The Witcher III')->first();
            expect($game->normalized_name)->toBe(GameNameNormalizer::normalize('The Witcher III'));
        });
    });

    // ── searchGamesIdSteam ────────────────────────────────────────────────────

    describe('searchGamesIdSteam()', function () {

        it('queries only games with steam_id AND steamcharts_searched_at both null', function () {
            // Jogo nunca buscado → deve ser incluído
            DB::table('games')->insert(['name' => 'Unsearched Game', 'steam_id' => null, 'steamcharts_searched_at' => null, 'created_at' => now(), 'updated_at' => now()]);

            // Jogo já buscado mas não encontrado → deve ser excluído
            DB::table('games')->insert(['name' => 'Already Searched', 'steam_id' => null, 'steamcharts_searched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            // Jogo já com ID Steam → deve ser excluído
            DB::table('games')->insert(['name' => 'Has Steam Id', 'steam_id' => '123456', 'steamcharts_searched_at' => null, 'created_at' => now(), 'updated_at' => now()]);

            Http::fake([
                '*/api/games/search-id-steam' => Http::response([
                    'success' => true,
                    'data' => ['games' => []],
                ], 200),
            ]);

            app(GameService::class)->searchGamesIdSteam();

            Http::assertSentCount(1);
            Http::assertSent(function ($request) {
                $names = array_column($request->data()['games'], 'name');

                return in_array('Unsearched Game', $names)
                    && ! in_array('Already Searched', $names)
                    && ! in_array('Has Steam Id', $names);
            });
        });

        it('marks all sent games with steamcharts_searched_at after a successful response', function () {
            DB::table('games')->insert(['name' => 'Game A', 'steam_id' => null, 'steamcharts_searched_at' => null, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('games')->insert(['name' => 'Game B', 'steam_id' => null, 'steamcharts_searched_at' => null, 'created_at' => now(), 'updated_at' => now()]);

            Http::fake([
                '*/api/games/search-id-steam' => Http::response([
                    'success' => true,
                    'data' => ['games' => []], // Nenhum encontrado
                ], 200),
            ]);

            app(GameService::class)->searchGamesIdSteam();

            // Mesmo sem encontrar IDs, os jogos devem ser marcados como pesquisados
            $unmarked = DB::table('games')
                ->whereNull('steamcharts_searched_at')
                ->whereIn('name', ['Game A', 'Game B'])
                ->count();

            expect($unmarked)->toBe(0);
        });

        it('sets steam_id on found games and marks them as searched', function () {
            $gameId = DB::table('games')->insertGetId(['name' => 'Found Game', 'steam_id' => null, 'steamcharts_searched_at' => null, 'created_at' => now(), 'updated_at' => now()]);

            Http::fake([
                '*/api/games/search-id-steam' => Http::response([
                    'success' => true,
                    'data' => ['games' => [
                        ['id' => $gameId, 'name' => 'Found Game', 'id_steam' => '999888'],
                    ]],
                ], 200),
            ]);

            app(GameService::class)->searchGamesIdSteam();

            $game = DB::table('games')->where('id', $gameId)->first();

            expect($game->steam_id)->toBe('999888')
                ->and($game->steamcharts_searched_at)->not->toBeNull();
        });

        it('does NOT mark games as searched when the HTTP call fails', function () {
            DB::table('games')->insert(['name' => 'Pending Game', 'steam_id' => null, 'steamcharts_searched_at' => null, 'created_at' => now(), 'updated_at' => now()]);

            Http::fake([
                '*/api/games/search-id-steam' => Http::response(['success' => false], 500),
            ]);

            app(GameService::class)->searchGamesIdSteam();

            $game = DB::table('games')->where('name', 'Pending Game')->first();

            // Falha HTTP → steamcharts_searched_at deve permanecer null para retry futuro
            expect($game->steamcharts_searched_at)->toBeNull();
        });

        it('does nothing when there are no unsearched games', function () {
            // Todos os jogos já foram buscados
            DB::table('games')->insert(['name' => 'Searched Game', 'steam_id' => null, 'steamcharts_searched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            Http::fake();

            app(GameService::class)->searchGamesIdSteam();

            // Nenhuma requisição deve ser enviada ao price_researcher
            Http::assertNothingSent();
        });
    });
});
