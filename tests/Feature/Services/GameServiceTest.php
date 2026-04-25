<?php

/*
|--------------------------------------------------------------------------
| GameService — characterization tests
|--------------------------------------------------------------------------
|
| Cobre as operações de infraestrutura de jogos:
|   - getIdGamivo: busca prioritária nas keys, fallback nos games
|   - fillIdGamivo: preenche id_gamivo nulo em game existente
|   - createGameIfDontExists: find-or-create case-insensitive
|   - updateMinPrices: degradação de preço por tempo (delega ao Domain)
|
| Regra de degradação de preço (PRODUCT.md + KeyPriceAging):
|   < 3 meses  → sem alteração
|   3–6 meses  → individual_cost × 1.4
|   6–9 meses  → individual_cost × 1.3
|   9–12 meses → individual_cost × 1.2
|   ≥ 12 meses → piso de 0.02 (MinMaxPriceCalculator::FLOOR)
|
*/

use App\Domain\Pricing\MinMaxPriceCalculator;
use App\Services\Games\GameService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedGameServiceFks(): void
{
    DB::table('suppliers')->insertOrIgnore(['id' => 1, 'supplier_url' => 'https://steamcommunity.com/id/seed']);
}

function insertListedKey(array $overrides = []): int
{
    return DB::table('keys')->insertGetId(array_merge([
        'game_name' => 'Listed Game',
        'key_code' => 'LISTED-'.uniqid(),
        'market_price' => 5.00,
        'individual_cost' => 1.00,
        'purchase_profit_percent' => 25.00,
        'min_api' => 1.50,
        'max_api' => 10.00,
        'supplier_url' => 'https://steamcommunity.com/id/seed',
        'supplier_id' => 1,
        'claim_type' => 'Nenhuma',
        'key_format' => 'RK',
        'sell_platform' => 'Gamivo',
        'listed_at' => now()->subDays(5)->toDateString(), // Já listada
        'sold_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
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
            DB::table('games')->insert(['name' => 'Priority Game', 'region' => null, 'id_gamivo' => 'gam-from-game', 'created_at' => now(), 'updated_at' => now()]);

            $result = app(GameService::class)->getIdGamivo('Priority Game', null);

            expect($result)->toBe('gam-from-key');
        });

        it('falls back to the games table when no key has the gamivo_id', function () {
            DB::table('games')->insert(['name' => 'Fallback Game', 'region' => null, 'id_gamivo' => 'gam-fallback-001', 'created_at' => now(), 'updated_at' => now()]);

            $result = app(GameService::class)->getIdGamivo('Fallback Game', null);

            expect($result)->toBe('gam-fallback-001');
        });

        it('returns false when the gamivo_id is not found in either table', function () {
            $result = app(GameService::class)->getIdGamivo('Completely Unknown Game', null);

            expect($result)->toBeFalse();
        });

        it('is case-insensitive when matching the game name', function () {
            DB::table('games')->insert(['name' => 'case sensitive game', 'region' => null, 'id_gamivo' => 'gam-case-001', 'created_at' => now(), 'updated_at' => now()]);

            $result = app(GameService::class)->getIdGamivo('Case Sensitive Game', null);

            expect($result)->toBe('gam-case-001');
        });

        it('matches by region — same name in different regions are different games', function () {
            DB::table('games')->insert(['name' => 'Regional Game', 'region' => 'EU', 'id_gamivo' => 'gam-eu-001', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('games')->insert(['name' => 'Regional Game', 'region' => 'NA', 'id_gamivo' => 'gam-na-001', 'created_at' => now(), 'updated_at' => now()]);

            expect(app(GameService::class)->getIdGamivo('Regional Game', 'EU'))->toBe('gam-eu-001')
                ->and(app(GameService::class)->getIdGamivo('Regional Game', 'NA'))->toBe('gam-na-001');
        });
    });

    // ── fillIdGamivo ──────────────────────────────────────────────────────────

    describe('fillIdGamivo()', function () {

        it('fills id_gamivo on the game when it is currently null', function () {
            DB::table('games')->insert(['name' => 'Fill Me Game', 'region' => null, 'id_gamivo' => null, 'created_at' => now(), 'updated_at' => now()]);

            app(GameService::class)->fillIdGamivo('Fill Me Game', null, 'gam-new-id');

            $game = DB::table('games')->where('name', 'Fill Me Game')->first();
            expect($game->id_gamivo)->toBe('gam-new-id');
        });

        it('does not overwrite id_gamivo when it is already set', function () {
            DB::table('games')->insert(['name' => 'Already Has Id', 'region' => null, 'id_gamivo' => 'gam-original', 'created_at' => now(), 'updated_at' => now()]);

            app(GameService::class)->fillIdGamivo('Already Has Id', null, 'gam-should-not-overwrite');

            $game = DB::table('games')->where('name', 'Already Has Id')->first();
            expect($game->id_gamivo)->toBe('gam-original');
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
            expect($game->id_gamivo)->toBe('gam-stored-001');
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
    });

    // ── updateMinPrices ───────────────────────────────────────────────────────

    describe('updateMinPrices()', function () {

        it('does not change min_api for keys listed less than 3 months ago', function () {
            // 1 mês listada → abaixo do primeiro tier → sem alteração
            insertListedKey([
                'key_code' => 'RECENT-LISTED-001',
                'individual_cost' => 1.00,
                'min_api' => 1.50,
                'listed_at' => Carbon::now()->subMonths(1)->toDateString(),
            ]);

            app(GameService::class)->updateMinPrices();

            $row = DB::table('keys')->where('key_code', 'RECENT-LISTED-001')->first();
            expect((float) $row->min_api)->toBe(1.50); // Sem alteração
        });

        it('applies 1.4x multiplier for keys listed between 3 and 6 months', function () {
            // 4 meses listada → tier de 3 meses → 1.0 × 1.4 = 1.4
            insertListedKey([
                'key_code' => 'MED-LISTED-001',
                'individual_cost' => 1.00,
                'min_api' => 1.50,
                'listed_at' => Carbon::now()->subMonths(4)->toDateString(),
            ]);

            app(GameService::class)->updateMinPrices();

            $row = DB::table('keys')->where('key_code', 'MED-LISTED-001')->first();
            expect((float) $row->min_api)->toEqualWithDelta(1.4, 0.001);
        });

        it('applies 1.3x multiplier for keys listed between 6 and 9 months', function () {
            // 7 meses listada → tier de 6 meses → 1.0 × 1.3 = 1.3
            insertListedKey([
                'key_code' => 'OLD-LISTED-001',
                'individual_cost' => 1.00,
                'min_api' => 1.50,
                'listed_at' => Carbon::now()->subMonths(7)->toDateString(),
            ]);

            app(GameService::class)->updateMinPrices();

            $row = DB::table('keys')->where('key_code', 'OLD-LISTED-001')->first();
            expect((float) $row->min_api)->toEqualWithDelta(1.3, 0.001);
        });

        it('applies the price floor for keys listed more than 12 months (clearance sell)', function () {
            // Jogo muito antigo → vende pelo piso mínimo independente de lucro
            insertListedKey([
                'key_code' => 'ANCIENT-LISTED-001',
                'individual_cost' => 1.00,
                'min_api' => 1.50,
                'listed_at' => Carbon::now()->subMonths(13)->toDateString(),
            ]);

            app(GameService::class)->updateMinPrices();

            $row = DB::table('keys')->where('key_code', 'ANCIENT-LISTED-001')->first();
            expect((float) $row->min_api)->toEqualWithDelta(MinMaxPriceCalculator::FLOOR, 0.001);
        });

        it('skips keys that are already sold (sold_at set)', function () {
            // Key vendida não deve ter min_api alterado
            insertListedKey([
                'key_code' => 'SOLD-OLD-001',
                'individual_cost' => 1.00,
                'min_api' => 1.50,
                'listed_at' => Carbon::now()->subMonths(4)->toDateString(),
                'sold_at' => Carbon::now()->subDays(2)->toDateString(),
            ]);

            app(GameService::class)->updateMinPrices();

            $row = DB::table('keys')->where('key_code', 'SOLD-OLD-001')->first();
            expect((float) $row->min_api)->toBe(1.50); // Sem alteração
        });

        it('processes at most 10 keys per call', function () {
            // Insere 15 keys todas com 4 meses de listagem → todas elegíveis para atualização
            for ($i = 1; $i <= 15; $i++) {
                insertListedKey([
                    'key_code' => "BATCH-KEY-{$i}",
                    'individual_cost' => 1.00,
                    'min_api' => 1.50, // Valor original
                    'listed_at' => Carbon::now()->subMonths(4)->toDateString(),
                ]);
            }

            app(GameService::class)->updateMinPrices();

            // Após 4 meses, min_api = 1.0 × 1.4 = 1.4
            $updatedCount = DB::table('keys')
                ->where('min_api', 1.4)
                ->count();

            // No máximo 10 keys devem ter sido atualizadas
            expect($updatedCount)->toBeLessThanOrEqual(10)
                ->and($updatedCount)->toBeGreaterThan(0);
        });
    });

    // ── searchGamesIdSteam ────────────────────────────────────────────────────

    describe('searchGamesIdSteam()', function () {

        it('queries only games with steamcharts_id AND steamcharts_searched_at both null', function () {
            // Jogo nunca buscado → deve ser incluído
            DB::table('games')->insert(['name' => 'Unsearched Game', 'steamcharts_id' => null, 'steamcharts_searched_at' => null, 'created_at' => now(), 'updated_at' => now()]);

            // Jogo já buscado mas não encontrado → deve ser excluído
            DB::table('games')->insert(['name' => 'Already Searched', 'steamcharts_id' => null, 'steamcharts_searched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            // Jogo já com ID Steam → deve ser excluído
            DB::table('games')->insert(['name' => 'Has Steam Id', 'steamcharts_id' => '123456', 'steamcharts_searched_at' => null, 'created_at' => now(), 'updated_at' => now()]);

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
            DB::table('games')->insert(['name' => 'Game A', 'steamcharts_id' => null, 'steamcharts_searched_at' => null, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('games')->insert(['name' => 'Game B', 'steamcharts_id' => null, 'steamcharts_searched_at' => null, 'created_at' => now(), 'updated_at' => now()]);

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

        it('sets steamcharts_id on found games and marks them as searched', function () {
            $gameId = DB::table('games')->insertGetId(['name' => 'Found Game', 'steamcharts_id' => null, 'steamcharts_searched_at' => null, 'created_at' => now(), 'updated_at' => now()]);

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

            expect($game->steamcharts_id)->toBe('999888')
                ->and($game->steamcharts_searched_at)->not->toBeNull();
        });

        it('does NOT mark games as searched when the HTTP call fails', function () {
            DB::table('games')->insert(['name' => 'Pending Game', 'steamcharts_id' => null, 'steamcharts_searched_at' => null, 'created_at' => now(), 'updated_at' => now()]);

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
            DB::table('games')->insert(['name' => 'Searched Game', 'steamcharts_id' => null, 'steamcharts_searched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            Http::fake();

            app(GameService::class)->searchGamesIdSteam();

            // Nenhuma requisição deve ser enviada ao price_researcher
            Http::assertNothingSent();
        });
    });
});
