<?php

/*
|--------------------------------------------------------------------------
| UpdateSoldOffersUseCase — characterization tests
|--------------------------------------------------------------------------
|
| Cobre o recebimento de dados de venda da API Gamivo e a atualização
| das keys correspondentes no banco.
|
| Regras documentadas em PRODUCT.md:
|   - Uma key pode ser vendida com lucro, zero lucro ou prejuízo.
|   - Keys já vendidas (valorVendido preenchido) não devem ser sobreescritas.
|   - Keys não encontradas são silenciosamente ignoradas.
|
*/

use App\UseCases\Keys\UpdateSoldOffersUseCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedSoldOffersFks(): void
{
    DB::table('taxas')->insert([
        ['name' => 'gamivoPercentualMenor', 'preco' => 0.072, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivoFixoMenor',       'preco' => 0.110, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivoPercentualMaior', 'preco' => 0.102, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivoFixoMaior',       'preco' => 0.550, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('recursos')->insert([
        ['name' => 'TF2', 'preco_euro' => 2.0, 'preco_dolar' => 2.2, 'preco_real' => 10.0, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('fornecedor')->insert(['id' => 1, 'perfilOrigem' => 'https://steamcommunity.com/id/seed']);
}

/**
 * Insere uma key ainda não vendida com um custo individual conhecido.
 */
function insertUnsoldKey(string $keyCode, float $individualCost = 2.00): void
{
    DB::table('venda_chave_trocas')->insert([
        'nomeJogo' => 'Test Game',
        'idGamivo' => 'gam-'.uniqid(),
        'chaveRecebida' => $keyCode,
        'precoCliente' => 5.00,
        'valorPagoIndividual' => $individualCost,
        'lucroPercentual' => 25.00,
        'perfilOrigem' => 'https://steamcommunity.com/id/test',
        'id_fornecedor' => 1,
        'claim_type' => 'Nenhuma',
        'key_format' => 'RK',
        'sell_platform' => 'Gamivo',
        'dataVenda' => now()->subDays(10)->toDateString(),
        'dataVendida' => null,
        'valorVendido' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('UpdateSoldOffersUseCase', function () {

    beforeEach(function () {
        seedSoldOffersFks();
        Cache::flush();
    });

    // ── Happy path ────────────────────────────────────────────────────────────

    it('marks the key as sold with dataVendida and valorVendido', function () {
        insertUnsoldKey('SOLD-KEY-001');

        app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['SOLD-KEY-001'], 'profit' => 5.00, 'saleDate' => '2024-06-01'],
        ]);

        $row = DB::table('venda_chave_trocas')->where('chaveRecebida', 'SOLD-KEY-001')->first();

        expect($row->dataVendida)->toBe('2024-06-01')
            ->and((float) $row->valorVendido)->toBe(5.00);
    });

    it('calculates lucroVendaRS as salePrice minus individualCost', function () {
        // lucroVendaRS = 5.00 - 2.00 = 3.00
        insertUnsoldKey('PROFIT-KEY-001', individualCost: 2.00);

        app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['PROFIT-KEY-001'], 'profit' => 5.00, 'saleDate' => '2024-06-01'],
        ]);

        $row = DB::table('venda_chave_trocas')->where('chaveRecebida', 'PROFIT-KEY-001')->first();

        expect((float) $row->lucroVendaRS)->toEqualWithDelta(3.00, 0.001);
    });

    it('calculates lucroVendaPercentual relative to the individual cost', function () {
        // lucroVendaPercentual = (3.00 / 2.00) × 100 = 150%
        insertUnsoldKey('PROFIT-KEY-002', individualCost: 2.00);

        app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['PROFIT-KEY-002'], 'profit' => 5.00, 'saleDate' => '2024-06-01'],
        ]);

        $row = DB::table('venda_chave_trocas')->where('chaveRecebida', 'PROFIT-KEY-002')->first();

        expect((float) $row->lucroVendaPercentual)->toEqualWithDelta(150.0, 0.01);
    });

    it('records zero profit when sold exactly at cost', function () {
        insertUnsoldKey('ZERO-KEY-001', individualCost: 3.00);

        app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['ZERO-KEY-001'], 'profit' => 3.00, 'saleDate' => '2024-06-01'],
        ]);

        $row = DB::table('venda_chave_trocas')->where('chaveRecebida', 'ZERO-KEY-001')->first();

        expect((float) $row->lucroVendaRS)->toEqualWithDelta(0.0, 0.001);
    });

    it('records a loss (negative lucroVendaRS) when sold below cost', function () {
        // Cenário real: jogo desvalorizou após bundle, vendido abaixo do custo
        // lucroVendaRS = 1.00 - 3.00 = -2.00
        insertUnsoldKey('LOSS-KEY-001', individualCost: 3.00);

        app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['LOSS-KEY-001'], 'profit' => 1.00, 'saleDate' => '2024-06-01'],
        ]);

        $row = DB::table('venda_chave_trocas')->where('chaveRecebida', 'LOSS-KEY-001')->first();

        expect((float) $row->lucroVendaRS)->toEqualWithDelta(-2.00, 0.001);
    });

    it('returns an empty array when all keys were updated successfully', function () {
        insertUnsoldKey('CLEAN-KEY-001');

        $notUpdated = app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['CLEAN-KEY-001'], 'profit' => 5.00, 'saleDate' => '2024-06-01'],
        ]);

        expect($notUpdated)->toBeEmpty();
    });

    // ── Multiple keys in one game ─────────────────────────────────────────────

    it('updates all keys listed in the same game object', function () {
        insertUnsoldKey('MULTI-KEY-001');
        insertUnsoldKey('MULTI-KEY-002');

        app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['MULTI-KEY-001', 'MULTI-KEY-002'], 'profit' => 5.00, 'saleDate' => '2024-06-01'],
        ]);

        $updated = DB::table('venda_chave_trocas')
            ->whereIn('chaveRecebida', ['MULTI-KEY-001', 'MULTI-KEY-002'])
            ->whereNotNull('dataVendida')
            ->count();

        expect($updated)->toBe(2);
    });

    it('processes multiple game objects in a single execute call', function () {
        insertUnsoldKey('GAME-A-KEY-001', individualCost: 2.00);
        insertUnsoldKey('GAME-B-KEY-001', individualCost: 4.00);

        app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['GAME-A-KEY-001'], 'profit' => 5.00, 'saleDate' => '2024-06-01'],
            ['keys' => ['GAME-B-KEY-001'], 'profit' => 8.00, 'saleDate' => '2024-06-01'],
        ]);

        $soldCount = DB::table('venda_chave_trocas')
            ->whereIn('chaveRecebida', ['GAME-A-KEY-001', 'GAME-B-KEY-001'])
            ->whereNotNull('dataVendida')
            ->count();

        expect($soldCount)->toBe(2);
    });

    // ── Exclusion rules ───────────────────────────────────────────────────────

    it('silently skips a key not found in the database', function () {
        // Não insere nenhuma key — execute deve retornar vazio sem exceção
        $notUpdated = app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['GHOST-KEY-999'], 'profit' => 5.00, 'saleDate' => '2024-06-01'],
        ]);

        expect($notUpdated)->toBeEmpty();
    });

    it('does not overwrite a key that was already sold', function () {
        // Key já possui valorVendido = 3.00 (venda anterior)
        DB::table('venda_chave_trocas')->insert([
            'nomeJogo' => 'Already Sold Game',
            'chaveRecebida' => 'ALREADY-SOLD-001',
            'precoCliente' => 5.00,
            'valorPagoIndividual' => 2.00,
            'lucroPercentual' => 25.00,
            'perfilOrigem' => 'https://steamcommunity.com/id/test',
            'id_fornecedor' => 1,
            'claim_type' => 'Nenhuma',
            'key_format' => 'RK',
            'sell_platform' => 'Gamivo',
            'dataVenda' => now()->subDays(15)->toDateString(),
            'dataVendida' => now()->subDays(5)->toDateString(),
            'valorVendido' => 3.00, // Já foi vendida
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['ALREADY-SOLD-001'], 'profit' => 99.00, 'saleDate' => '2024-06-01'],
        ]);

        $row = DB::table('venda_chave_trocas')->where('chaveRecebida', 'ALREADY-SOLD-001')->first();

        // valorVendido deve permanecer 3.00 — não sobreescrito
        expect((float) $row->valorVendido)->toBe(3.00);
    });
});
