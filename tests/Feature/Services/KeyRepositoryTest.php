<?php

/*
|--------------------------------------------------------------------------
| KeyRepository — characterization tests
|--------------------------------------------------------------------------
|
| Cobre as duas queries centrais do repositório:
|   - findByKeyCode: busca por código de ativação (com e sem excludeId)
|   - findEligibleForAutoSell: aplica as regras de elegibilidade para venda
|
| As regras de elegibilidade espelham os scopes do model Venda_chave_troca
| e a constante BUNDLE_EXCLUSION_DAYS de KeyEligibility (21 dias).
|
*/

use App\Services\Keys\KeyRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedRepoFks(): void
{
    DB::table('tipo_reclamacao')->insertOrIgnore(['id' => 1, 'name' => 'Nenhuma']);
    DB::table('tipo_formato')->insertOrIgnore(['id' => 1, 'name' => 'Key']);
    DB::table('tipo_leilao')->insertOrIgnore(['id' => 1, 'name' => 'Fixo']);
    DB::table('plataforma')->insertOrIgnore(['id' => 1, 'name' => 'Gamivo']);
    DB::table('fornecedor')->insertOrIgnore(['id' => 1, 'perfilOrigem' => 'https://steamcommunity.com/id/seed']);
}

function insertRepoKey(array $overrides = []): int
{
    return DB::table('venda_chave_trocas')->insertGetId(array_merge([
        'nomeJogo'            => 'Repo Test Game',
        'idGamivo'            => 'gam-' . uniqid(),
        'chaveRecebida'       => 'REPO-KEY-' . uniqid(),
        'precoCliente'        => 5.00,
        'valorPagoIndividual' => 2.00,
        'lucroPercentual'     => 25.00,
        'perfilOrigem'        => 'https://steamcommunity.com/id/test',
        'id_fornecedor'       => 1,
        'tipo_reclamacao_id'  => 1,
        'tipo_formato_id'     => 1,
        'id_leilao_g2a'       => 1,
        'id_leilao_gamivo'    => 1,
        'id_leilao_kinguin'   => 1,
        'id_plataforma'       => 1,
        'dataVenda'           => null,
        'dataVendida'         => null,
        'created_at'          => now(),
        'updated_at'          => now(),
    ], $overrides));
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('KeyRepository', function () {

    beforeEach(fn () => seedRepoFks());

    // ── findByKeyCode ─────────────────────────────────────────────────────────

    describe('findByKeyCode()', function () {

        it('returns the key when found by its activation code', function () {
            insertRepoKey(['chaveRecebida' => 'FIND-ME-12345']);

            $result = app(KeyRepository::class)->findByKeyCode('FIND-ME-12345');

            expect($result)->not->toBeNull()
                ->and($result->chaveRecebida)->toBe('FIND-ME-12345');
        });

        it('returns null when the key code does not exist', function () {
            $result = app(KeyRepository::class)->findByKeyCode('GHOST-KEY-99999');

            expect($result)->toBeNull();
        });

        it('excludes the own record when excludeId is provided', function () {
            // Dois registros com o mesmo código — ao excluir o id1, deve retornar id2
            $id1 = insertRepoKey(['chaveRecebida' => 'DUPE-CODE-001']);
            $id2 = insertRepoKey(['chaveRecebida' => 'DUPE-CODE-001']);

            $result = app(KeyRepository::class)->findByKeyCode('DUPE-CODE-001', $id1);

            expect($result)->not->toBeNull()
                ->and($result->id)->toBe($id2);
        });

        it('returns the record itself when excludeId refers to a different record', function () {
            $id1 = insertRepoKey(['chaveRecebida' => 'SOLO-CODE-001']);
            $id2 = insertRepoKey(['chaveRecebida' => 'ANOTHER-CODE-002']);

            // Excluindo id2, o registro id1 ainda deve aparecer
            $result = app(KeyRepository::class)->findByKeyCode('SOLO-CODE-001', $id2);

            expect($result)->not->toBeNull()
                ->and($result->id)->toBe($id1);
        });

        it('returns null when the only matching record is excluded by excludeId', function () {
            $id = insertRepoKey(['chaveRecebida' => 'ONLY-ONE-001']);

            $result = app(KeyRepository::class)->findByKeyCode('ONLY-ONE-001', $id);

            expect($result)->toBeNull();
        });
    });

    // ── findEligibleForAutoSell ───────────────────────────────────────────────

    describe('findEligibleForAutoSell()', function () {

        it('returns a key that meets all eligibility criteria', function () {
            insertRepoKey(['idGamivo' => 'gam-eligible-001', 'chaveRecebida' => 'ELIG-KEY-001']);

            $results = app(KeyRepository::class)->findEligibleForAutoSell();

            expect($results->pluck('chaveRecebida'))->toContain('ELIG-KEY-001');
        });

        it('excludes a key without idGamivo', function () {
            insertRepoKey(['idGamivo' => null, 'chaveRecebida' => 'NO-GAMIVO-001']);

            $results = app(KeyRepository::class)->findEligibleForAutoSell();

            expect($results->pluck('chaveRecebida'))->not->toContain('NO-GAMIVO-001');
        });

        it('excludes a key with empty string idGamivo', function () {
            insertRepoKey(['idGamivo' => '', 'chaveRecebida' => 'EMPTY-GAMIVO-001']);

            $results = app(KeyRepository::class)->findEligibleForAutoSell();

            expect($results->pluck('chaveRecebida'))->not->toContain('EMPTY-GAMIVO-001');
        });

        it('excludes a key already listed for sale (dataVenda set)', function () {
            insertRepoKey([
                'idGamivo'     => 'gam-listed-001',
                'chaveRecebida' => 'LISTED-KEY-001',
                'dataVenda'    => Carbon::now()->subDays(5)->toDateString(),
            ]);

            $results = app(KeyRepository::class)->findEligibleForAutoSell();

            expect($results->pluck('chaveRecebida'))->not->toContain('LISTED-KEY-001');
        });

        it('excludes a key already sold (dataVendida set)', function () {
            insertRepoKey([
                'idGamivo'     => 'gam-sold-001',
                'chaveRecebida' => 'SOLD-REPO-001',
                'dataVendida'  => Carbon::now()->subDays(10)->toDateString(),
            ]);

            $results = app(KeyRepository::class)->findEligibleForAutoSell();

            expect($results->pluck('chaveRecebida'))->not->toContain('SOLD-REPO-001');
        });

        it('excludes gift links (chaveRecebida containing "http")', function () {
            insertRepoKey([
                'idGamivo'     => 'gam-gift-001',
                'chaveRecebida' => 'https://store.steampowered.com/gift/abc123',
            ]);

            $results = app(KeyRepository::class)->findEligibleForAutoSell();

            $links = $results->pluck('chaveRecebida')->filter(fn ($k) => str_contains($k, 'http'));
            expect($links)->toBeEmpty();
        });

        it('excludes a key whose game is in a bundle released less than 21 days ago', function () {
            $keyCode  = 'RECENT-BUNDLE-KEY';
            $idGamivo = 'gam-recent-001';

            insertRepoKey(['idGamivo' => $idGamivo, 'chaveRecebida' => $keyCode]);

            $gameId   = DB::table('games')->insertGetId(['name' => 'Recent Game', 'id_gamivo' => $idGamivo, 'created_at' => now(), 'updated_at' => now()]);
            $bundleId = DB::table('bundles')->insertGetId(['name' => 'Recent Bundle', 'type' => 'bundle', 'release_date' => Carbon::now()->subDays(10)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('bundle_games')->insert(['bundle_id' => $bundleId, 'game_id' => $gameId, 'created_at' => now(), 'updated_at' => now()]);

            $results = app(KeyRepository::class)->findEligibleForAutoSell();

            expect($results->pluck('chaveRecebida'))->not->toContain($keyCode);
        });

        it('includes a key whose game is in a bundle released more than 21 days ago', function () {
            $keyCode  = 'OLD-BUNDLE-KEY';
            $idGamivo = 'gam-old-001';

            insertRepoKey(['idGamivo' => $idGamivo, 'chaveRecebida' => $keyCode]);

            $gameId   = DB::table('games')->insertGetId(['name' => 'Old Bundle Game', 'id_gamivo' => $idGamivo, 'created_at' => now(), 'updated_at' => now()]);
            $bundleId = DB::table('bundles')->insertGetId(['name' => 'Old Bundle', 'type' => 'bundle', 'release_date' => Carbon::now()->subDays(30)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('bundle_games')->insert(['bundle_id' => $bundleId, 'game_id' => $gameId, 'created_at' => now(), 'updated_at' => now()]);

            $results = app(KeyRepository::class)->findEligibleForAutoSell();

            expect($results->pluck('chaveRecebida'))->toContain($keyCode);
        });

        it('returns only eligible keys when mixed statuses coexist', function () {
            insertRepoKey(['idGamivo' => 'gam-ok-1', 'chaveRecebida' => 'OK-KEY-001']);
            insertRepoKey(['idGamivo' => 'gam-ok-2', 'chaveRecebida' => 'OK-KEY-002']);
            insertRepoKey(['idGamivo' => null,        'chaveRecebida' => 'NO-ID-KEY-001']);
            insertRepoKey(['idGamivo' => 'gam-sold',  'chaveRecebida' => 'SOLD-MIX-001', 'dataVendida' => now()->toDateString()]);

            $results = app(KeyRepository::class)->findEligibleForAutoSell();
            $codes   = $results->pluck('chaveRecebida');

            expect($codes)->toContain('OK-KEY-001')
                ->and($codes)->toContain('OK-KEY-002')
                ->and($codes)->not->toContain('NO-ID-KEY-001')
                ->and($codes)->not->toContain('SOLD-MIX-001');
        });
    });
});
