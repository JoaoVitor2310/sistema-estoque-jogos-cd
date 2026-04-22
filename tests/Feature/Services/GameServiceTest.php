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
|   3–6 meses  → valorPagoIndividual × 1.4
|   6–9 meses  → valorPagoIndividual × 1.3
|   9–12 meses → valorPagoIndividual × 1.2
|   ≥ 12 meses → piso de 0.02 (MinMaxPriceCalculator::FLOOR)
|
*/

use App\Domain\Pricing\MinMaxPriceCalculator;
use App\Services\Games\GameService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedGameServiceFks(): void
{
    DB::table('fornecedor')->insertOrIgnore(['id' => 1, 'perfilOrigem' => 'https://steamcommunity.com/id/seed']);
}

function insertListedKey(array $overrides = []): int
{
    return DB::table('venda_chave_trocas')->insertGetId(array_merge([
        'nomeJogo' => 'Listed Game',
        'chaveRecebida' => 'LISTED-'.uniqid(),
        'precoCliente' => 5.00,
        'valorPagoIndividual' => 1.00,
        'lucroPercentual' => 25.00,
        'minApiGamivo' => 1.50,
        'maxApiGamivo' => 10.00,
        'perfilOrigem' => 'https://steamcommunity.com/id/seed',
        'id_fornecedor' => 1,
        'claim_type' => 'Nenhuma',
        'key_format' => 'RK',
        'sell_platform' => 'Gamivo',
        'dataVenda' => now()->subDays(5)->toDateString(), // Já listada
        'dataVendida' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('GameService', function () {

    beforeEach(fn () => seedGameServiceFks());

    // ── getIdGamivo ───────────────────────────────────────────────────────────

    describe('getIdGamivo()', function () {

        it('finds id_gamivo from venda_chave_trocas first (priority over games table)', function () {
            // Key tem um idGamivo — deve ser retornado sem consultar games
            DB::table('venda_chave_trocas')->insert([
                'nomeJogo' => 'Priority Game',
                'chaveRecebida' => 'PRIO-KEY-001',
                'idGamivo' => 'gam-from-key',
                'precoCliente' => 5.00,
                'valorPagoIndividual' => 2.00,
                'lucroPercentual' => 25.00,
                'perfilOrigem' => 'https://steamcommunity.com/id/seed',
                'id_fornecedor' => 1,
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

        it('falls back to the games table when no key has the id_gamivo', function () {
            DB::table('games')->insert(['name' => 'Fallback Game', 'region' => null, 'id_gamivo' => 'gam-fallback-001', 'created_at' => now(), 'updated_at' => now()]);

            $result = app(GameService::class)->getIdGamivo('Fallback Game', null);

            expect($result)->toBe('gam-fallback-001');
        });

        it('returns false when the id_gamivo is not found in either table', function () {
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
                'nomeJogo' => 'Brand New Game',
                'region' => null,
                'idGamivo' => 'gam-new-001',
            ]);

            expect(DB::table('games')->where('name', 'Brand New Game')->exists())->toBeTrue();
        });

        it('stores idGamivo on the created game', function () {
            app(GameService::class)->createGameIfDontExists([
                'nomeJogo' => 'Game With Gamivo Id',
                'region' => null,
                'idGamivo' => 'gam-stored-001',
            ]);

            $game = DB::table('games')->where('name', 'Game With Gamivo Id')->first();
            expect($game->id_gamivo)->toBe('gam-stored-001');
        });

        it('does not create a duplicate when the game already exists (case-insensitive)', function () {
            DB::table('games')->insert(['name' => 'existing game', 'region' => null, 'created_at' => now(), 'updated_at' => now()]);

            app(GameService::class)->createGameIfDontExists([
                'nomeJogo' => 'Existing Game', // Casing diferente
                'region' => null,
                'idGamivo' => null,
            ]);

            $count = DB::table('games')->whereRaw('LOWER("name") = ?', ['existing game'])->count();
            expect($count)->toBe(1);
        });

        it('treats games with the same name but different regions as distinct records', function () {
            app(GameService::class)->createGameIfDontExists(['nomeJogo' => 'Multi Region Game', 'region' => 'EU', 'idGamivo' => null]);
            app(GameService::class)->createGameIfDontExists(['nomeJogo' => 'Multi Region Game', 'region' => 'NA', 'idGamivo' => null]);

            $count = DB::table('games')->where('name', 'Multi Region Game')->count();
            expect($count)->toBe(2);
        });

        it('is idempotent — calling twice does not create duplicates', function () {
            $data = ['nomeJogo' => 'Idempotent Game', 'region' => null, 'idGamivo' => null];

            app(GameService::class)->createGameIfDontExists($data);
            app(GameService::class)->createGameIfDontExists($data);

            $count = DB::table('games')->where('name', 'Idempotent Game')->count();
            expect($count)->toBe(1);
        });
    });

    // ── updateMinPrices ───────────────────────────────────────────────────────

    describe('updateMinPrices()', function () {

        it('does not change minApiGamivo for keys listed less than 3 months ago', function () {
            // 1 mês listada → abaixo do primeiro tier → sem alteração
            insertListedKey([
                'chaveRecebida' => 'RECENT-LISTED-001',
                'valorPagoIndividual' => 1.00,
                'minApiGamivo' => 1.50,
                'dataVenda' => Carbon::now()->subMonths(1)->toDateString(),
            ]);

            app(GameService::class)->updateMinPrices();

            $row = DB::table('venda_chave_trocas')->where('chaveRecebida', 'RECENT-LISTED-001')->first();
            expect((float) $row->minApiGamivo)->toBe(1.50); // Sem alteração
        });

        it('applies 1.4x multiplier for keys listed between 3 and 6 months', function () {
            // 4 meses listada → tier de 3 meses → 1.0 × 1.4 = 1.4
            insertListedKey([
                'chaveRecebida' => 'MED-LISTED-001',
                'valorPagoIndividual' => 1.00,
                'minApiGamivo' => 1.50,
                'dataVenda' => Carbon::now()->subMonths(4)->toDateString(),
            ]);

            app(GameService::class)->updateMinPrices();

            $row = DB::table('venda_chave_trocas')->where('chaveRecebida', 'MED-LISTED-001')->first();
            expect((float) $row->minApiGamivo)->toEqualWithDelta(1.4, 0.001);
        });

        it('applies 1.3x multiplier for keys listed between 6 and 9 months', function () {
            // 7 meses listada → tier de 6 meses → 1.0 × 1.3 = 1.3
            insertListedKey([
                'chaveRecebida' => 'OLD-LISTED-001',
                'valorPagoIndividual' => 1.00,
                'minApiGamivo' => 1.50,
                'dataVenda' => Carbon::now()->subMonths(7)->toDateString(),
            ]);

            app(GameService::class)->updateMinPrices();

            $row = DB::table('venda_chave_trocas')->where('chaveRecebida', 'OLD-LISTED-001')->first();
            expect((float) $row->minApiGamivo)->toEqualWithDelta(1.3, 0.001);
        });

        it('applies the price floor for keys listed more than 12 months (clearance sell)', function () {
            // Jogo muito antigo → vende pelo piso mínimo independente de lucro
            insertListedKey([
                'chaveRecebida' => 'ANCIENT-LISTED-001',
                'valorPagoIndividual' => 1.00,
                'minApiGamivo' => 1.50,
                'dataVenda' => Carbon::now()->subMonths(13)->toDateString(),
            ]);

            app(GameService::class)->updateMinPrices();

            $row = DB::table('venda_chave_trocas')->where('chaveRecebida', 'ANCIENT-LISTED-001')->first();
            expect((float) $row->minApiGamivo)->toEqualWithDelta(MinMaxPriceCalculator::FLOOR, 0.001);
        });

        it('skips keys that are already sold (dataVendida set)', function () {
            // Key vendida não deve ter minApiGamivo alterado
            insertListedKey([
                'chaveRecebida' => 'SOLD-OLD-001',
                'valorPagoIndividual' => 1.00,
                'minApiGamivo' => 1.50,
                'dataVenda' => Carbon::now()->subMonths(4)->toDateString(),
                'dataVendida' => Carbon::now()->subDays(2)->toDateString(),
            ]);

            app(GameService::class)->updateMinPrices();

            $row = DB::table('venda_chave_trocas')->where('chaveRecebida', 'SOLD-OLD-001')->first();
            expect((float) $row->minApiGamivo)->toBe(1.50); // Sem alteração
        });

        it('processes at most 10 keys per call', function () {
            // Insere 15 keys todas com 4 meses de listagem → todas elegíveis para atualização
            for ($i = 1; $i <= 15; $i++) {
                insertListedKey([
                    'chaveRecebida' => "BATCH-KEY-{$i}",
                    'valorPagoIndividual' => 1.00,
                    'minApiGamivo' => 1.50, // Valor original
                    'dataVenda' => Carbon::now()->subMonths(4)->toDateString(),
                ]);
            }

            app(GameService::class)->updateMinPrices();

            // Após 4 meses, minApiGamivo = 1.0 × 1.4 = 1.4
            $updatedCount = DB::table('venda_chave_trocas')
                ->where('minApiGamivo', 1.4)
                ->count();

            // No máximo 10 keys devem ter sido atualizadas
            expect($updatedCount)->toBeLessThanOrEqual(10)
                ->and($updatedCount)->toBeGreaterThan(0);
        });
    });
});
