<?php

/*
|--------------------------------------------------------------------------
| AutoSell — characterization tests
|--------------------------------------------------------------------------
|
| Covers VendaChaveTrocaController::autoSell()
| Route: GET /venda-chave-troca/auto-sell  (no auth — withoutMiddleware)
| Response shape: { "statusCode": 200, "message": "...", "data": [...] }
|
*/

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Insert a key that is eligible by default.
 * Pass $overrides to break specific eligibility rules.
 */
function createKey(array $overrides = []): void
{
    DB::table('venda_chave_trocas')->insert(array_merge([
        'nomeJogo'            => 'Test Game',
        'idGamivo'            => 'gam-' . uniqid(),
        'chaveRecebida'       => 'ABCDE-12345-FGHIJ',
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

function idGamivoList(array $data): array
{
    return array_column($data, 'idGamivo');
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('GET /venda-chave-troca/auto-sell', function () {

    beforeEach(function () {
        // Formulas is injected into the controller constructor and queries taxas
        DB::table('taxas')->insert([
            ['name' => 'gamivoPercentualMenor', 'preco' => 0.072, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'gamivoFixoMenor',       'preco' => 0.110, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'gamivoPercentualMaior', 'preco' => 0.102, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'gamivoFixoMaior',       'preco' => 0.550, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // FK reference rows required by venda_chave_trocas
        DB::table('tipo_reclamacao')->insert(['id' => 1, 'name' => 'Nenhuma']);
        DB::table('tipo_formato')->insert(['id' => 1, 'name' => 'Key']);
        DB::table('tipo_leilao')->insert(['id' => 1, 'name' => 'Fixo']);
        DB::table('plataforma')->insert(['id' => 1, 'name' => 'Gamivo']);
        DB::table('fornecedor')->insert(['id' => 1, 'perfilOrigem' => 'https://steamcommunity.com/id/seed']);
    });

    // ── Happy path ────────────────────────────────────────────────────────────

    it('returns an eligible key in the listing', function () {
        createKey(['idGamivo' => 'gam-eligible-001']);

        $data = $this->getJson('/venda-chave-troca/auto-sell')->assertOk()->json('data');

        expect(idGamivoList($data))->toContain('gam-eligible-001');
    });

    // ── Exclusion rules ───────────────────────────────────────────────────────

    describe('excludes a key when', function () {

        it('idGamivo is null', function () {
            createKey(['idGamivo' => null]);

            $data = $this->getJson('/venda-chave-troca/auto-sell')->assertOk()->json('data');

            expect($data)->toHaveCount(0);
        });

        it('idGamivo is an empty string', function () {
            createKey(['idGamivo' => '']);

            $data = $this->getJson('/venda-chave-troca/auto-sell')->assertOk()->json('data');

            expect($data)->toHaveCount(0);
        });

        it('the key has already been listed for sale (dataVenda is set)', function () {
            createKey([
                'idGamivo'  => 'gam-listed',
                'dataVenda' => Carbon::now()->subDays(5)->toDateString(),
            ]);

            $data = $this->getJson('/venda-chave-troca/auto-sell')->assertOk()->json('data');

            expect(idGamivoList($data))->not->toContain('gam-listed');
        });

        it('the key has already been sold (dataVendida is set)', function () {
            createKey([
                'idGamivo'    => 'gam-sold',
                'dataVendida' => Carbon::now()->subDays(10)->toDateString(),
            ]);

            $data = $this->getJson('/venda-chave-troca/auto-sell')->assertOk()->json('data');

            expect(idGamivoList($data))->not->toContain('gam-sold');
        });

        it('chaveRecebida contains "http" (gift link)', function () {
            createKey([
                'idGamivo'      => 'gam-gift',
                'chaveRecebida' => 'https://store.steampowered.com/gift/abc123',
            ]);

            $data = $this->getJson('/venda-chave-troca/auto-sell')->assertOk()->json('data');

            expect(idGamivoList($data))->not->toContain('gam-gift');
        });
    });

    // ── 21-day bundle rule ────────────────────────────────────────────────────

    describe('21-day bundle rule', function () {

        it('excludes a key whose game is in a bundle released less than 21 days ago', function () {
            $idGamivo = 'gam-recent-bundle';
            createKey(['idGamivo' => $idGamivo]);

            $gameId = DB::table('games')->insertGetId([
                'name' => 'Recent Bundle Game', 'id_gamivo' => $idGamivo,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $bundleId = DB::table('bundles')->insertGetId([
                'name' => 'Recent Bundle', 'type' => 'bundle',
                'release_date' => Carbon::now()->subDays(10)->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('bundle_games')->insert([
                'bundle_id' => $bundleId, 'game_id' => $gameId,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $data = $this->getJson('/venda-chave-troca/auto-sell')->assertOk()->json('data');

            expect(idGamivoList($data))->not->toContain($idGamivo);
        });

        it('includes a key whose game is in a bundle released more than 21 days ago', function () {
            $idGamivo = 'gam-old-bundle';
            createKey(['idGamivo' => $idGamivo]);

            $gameId = DB::table('games')->insertGetId([
                'name' => 'Old Bundle Game', 'id_gamivo' => $idGamivo,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $bundleId = DB::table('bundles')->insertGetId([
                'name' => 'Old Bundle', 'type' => 'bundle',
                'release_date' => Carbon::now()->subDays(30)->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('bundle_games')->insert([
                'bundle_id' => $bundleId, 'game_id' => $gameId,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $data = $this->getJson('/venda-chave-troca/auto-sell')->assertOk()->json('data');

            expect(idGamivoList($data))->toContain($idGamivo);
        });

        it('still excludes a key when the bundle was released exactly 20 days ago', function () {
            // The query uses: release_date > now()->subDays(21)
            // 20 days ago IS inside the window, so the key must be excluded.
            $idGamivo = 'gam-20-days';
            createKey(['idGamivo' => $idGamivo]);

            $gameId = DB::table('games')->insertGetId([
                'name' => '20-Day Game', 'id_gamivo' => $idGamivo,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $bundleId = DB::table('bundles')->insertGetId([
                'name' => '20-Day Bundle', 'type' => 'choice',
                'release_date' => Carbon::now()->subDays(20)->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('bundle_games')->insert([
                'bundle_id' => $bundleId, 'game_id' => $gameId,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $data = $this->getJson('/venda-chave-troca/auto-sell')->assertOk()->json('data');

            expect(idGamivoList($data))->not->toContain($idGamivo);
        });
    });

    // ── Combined ──────────────────────────────────────────────────────────────

    it('returns only eligible keys when multiple keys with different statuses exist', function () {
        createKey(['idGamivo' => 'gam-ok-1']);
        createKey(['idGamivo' => null]);
        createKey(['idGamivo' => 'gam-sold', 'dataVendida' => Carbon::now()->subDays(1)->toDateString()]);
        createKey(['idGamivo' => 'gam-ok-2']);

        $data = $this->getJson('/venda-chave-troca/auto-sell')->assertOk()->json('data');

        expect($data)->toHaveCount(2)
            ->and(idGamivoList($data))->toContain('gam-ok-1')
            ->and(idGamivoList($data))->toContain('gam-ok-2')
            ->and(idGamivoList($data))->not->toContain('gam-sold');
    });
});
