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
|   - Keys já vendidas (sold_price preenchido) não devem ser sobreescritas.
|   - Keys não encontradas são silenciosamente ignoradas.
|
*/

use App\UseCases\Keys\UpdateSoldOffersUseCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedSoldOffersFks(): void
{
    DB::table('fees')->insert([
        ['name' => 'gamivo_percent_low', 'preco' => 0.060, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_low',       'preco' => 0.250, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_percent_high', 'preco' => 0.080, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_high',       'preco' => 0.400, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('assets')->insert([
        ['name' => 'TF2', 'price_euro' => 2.0, 'price_dollar' => 2.2, 'price_brl' => 10.0, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('suppliers')->insert(['id' => 1, 'supplier_url' => 'https://steamcommunity.com/id/seed']);
}

/**
 * Insere uma key ainda não vendida com um custo individual conhecido.
 */
function insertUnsoldKey(string $keyCode, float $individualCost = 2.00): void
{
    DB::table('keys')->insert([
        'game_name' => 'Test Game',
        'gamivo_id' => 'gam-'.uniqid(),
        'key_code' => $keyCode,
        'market_price' => 5.00,
        'individual_cost' => $individualCost,
        'purchase_profit_percent' => 25.00,
        'supplier_url' => 'https://steamcommunity.com/id/test',
        'supplier_id' => 1,
        'claim_type' => 'Nenhuma',
        'key_format' => 'RK',
        'sell_platform' => 'Gamivo',
        'listed_at' => now()->subDays(10)->toDateString(),
        'sold_at' => null,
        'sold_price' => null,
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

    it('marks the key as sold with sold_at and sold_price', function () {
        insertUnsoldKey('SOLD-KEY-001');

        app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['SOLD-KEY-001'], 'profit' => 5.00, 'saleDate' => '2024-06-01'],
        ]);

        $row = DB::table('keys')->where('key_code', 'SOLD-KEY-001')->first();

        expect($row->sold_at)->toBe('2024-06-01')
            ->and((float) $row->sold_price)->toBe(5.00);
    });

    it('calculates sale_profit as salePrice minus individualCost', function () {
        // sale_profit = 5.00 - 2.00 = 3.00
        insertUnsoldKey('PROFIT-KEY-001', individualCost: 2.00);

        app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['PROFIT-KEY-001'], 'profit' => 5.00, 'saleDate' => '2024-06-01'],
        ]);

        $row = DB::table('keys')->where('key_code', 'PROFIT-KEY-001')->first();

        expect((float) $row->sale_profit)->toEqualWithDelta(3.00, 0.001);
    });

    it('calculates sale_profit_percent relative to the individual cost', function () {
        // sale_profit_percent = (3.00 / 2.00) × 100 = 150%
        insertUnsoldKey('PROFIT-KEY-002', individualCost: 2.00);

        app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['PROFIT-KEY-002'], 'profit' => 5.00, 'saleDate' => '2024-06-01'],
        ]);

        $row = DB::table('keys')->where('key_code', 'PROFIT-KEY-002')->first();

        expect((float) $row->sale_profit_percent)->toEqualWithDelta(150.0, 0.01);
    });

    it('records zero profit when sold exactly at cost', function () {
        insertUnsoldKey('ZERO-KEY-001', individualCost: 3.00);

        app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['ZERO-KEY-001'], 'profit' => 3.00, 'saleDate' => '2024-06-01'],
        ]);

        $row = DB::table('keys')->where('key_code', 'ZERO-KEY-001')->first();

        expect((float) $row->sale_profit)->toEqualWithDelta(0.0, 0.001);
    });

    it('records a loss (negative sale_profit) when sold below cost', function () {
        // Cenário real: jogo desvalorizou após bundle, vendido abaixo do custo
        // sale_profit = 1.00 - 3.00 = -2.00
        insertUnsoldKey('LOSS-KEY-001', individualCost: 3.00);

        app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['LOSS-KEY-001'], 'profit' => 1.00, 'saleDate' => '2024-06-01'],
        ]);

        $row = DB::table('keys')->where('key_code', 'LOSS-KEY-001')->first();

        expect((float) $row->sale_profit)->toEqualWithDelta(-2.00, 0.001);
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

        $updated = DB::table('keys')
            ->whereIn('key_code', ['MULTI-KEY-001', 'MULTI-KEY-002'])
            ->whereNotNull('sold_at')
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

        $soldCount = DB::table('keys')
            ->whereIn('key_code', ['GAME-A-KEY-001', 'GAME-B-KEY-001'])
            ->whereNotNull('sold_at')
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
        // Key já possui sold_price = 3.00 (venda anterior)
        DB::table('keys')->insert([
            'game_name' => 'Already Sold Game',
            'key_code' => 'ALREADY-SOLD-001',
            'market_price' => 5.00,
            'individual_cost' => 2.00,
            'purchase_profit_percent' => 25.00,
            'supplier_url' => 'https://steamcommunity.com/id/test',
            'supplier_id' => 1,
            'claim_type' => 'Nenhuma',
            'key_format' => 'RK',
            'sell_platform' => 'Gamivo',
            'listed_at' => now()->subDays(15)->toDateString(),
            'sold_at' => now()->subDays(5)->toDateString(),
            'sold_price' => 3.00, // Já foi vendida
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['ALREADY-SOLD-001'], 'profit' => 99.00, 'saleDate' => '2024-06-01'],
        ]);

        $row = DB::table('keys')->where('key_code', 'ALREADY-SOLD-001')->first();

        // sold_price deve permanecer 3.00 — não sobreescrito
        expect((float) $row->sold_price)->toBe(3.00);
    });
});
