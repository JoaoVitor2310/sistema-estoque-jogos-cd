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

use App\UseCases\Marketplaces\Gamivo\UpdateSoldOffersUseCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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

// ── executeFromGamivo ─────────────────────────────────────────────────────────

describe('UpdateSoldOffersUseCase::executeFromGamivo', function () {

    beforeEach(function () {
        seedSoldOffersFks();
        Cache::flush();
    });

    it('fetches sales from Gamivo API and marks the key as sold', function () {
        insertUnsoldKey('GAMIVO-KEY-001');

        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response([
                'count' => 1,
                'data' => [[
                    'order_id' => 'order-uuid-abc',
                    'profit' => 2.75,
                    'seller_tax' => 0.00,
                    'created_at' => '2025-05-06UTC10:00:000',
                ]],
            ], 200),
            // Segunda página vazia — encerra a paginação
            '*/accounts/sales/history/25/25*' => Http::response(['count' => 0, 'data' => []], 200),
            '*/accounts/sales/order-details/order-uuid-abc*' => Http::response([
                'id' => 'order-uuid-abc',
                'keys' => [
                    '9001' => [
                        'keys' => [['type' => 'TEXT', 'key' => 'GAMIVO-KEY-001']],
                        'rating' => '-',
                    ],
                ],
            ], 200),
        ]);

        app(UpdateSoldOffersUseCase::class)->executeFromGamivo();

        $row = DB::table('keys')->where('key_code', 'GAMIVO-KEY-001')->first();

        // profit = 2.75 + 0.00 - 0.01 = 2.74
        expect($row->sold_at)->toBe('2025-05-06')
            ->and((float) $row->sold_price)->toEqualWithDelta(2.74, 0.001);
    });

    it('adds seller_tax to the profit before recording', function () {
        insertUnsoldKey('GAMIVO-KEY-TAX');

        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response([
                'count' => 1,
                'data' => [[
                    'order_id' => 'order-tax-001',
                    'profit' => 2.00,
                    'seller_tax' => 0.50,
                    'created_at' => '2025-05-06UTC10:00:000',
                ]],
            ], 200),
            '*/accounts/sales/history/25/25*' => Http::response(['count' => 0, 'data' => []], 200),
            '*/accounts/sales/order-details/order-tax-001*' => Http::response([
                'id' => 'order-tax-001',
                'keys' => [
                    '9002' => [
                        'keys' => [['type' => 'TEXT', 'key' => 'GAMIVO-KEY-TAX']],
                        'rating' => '-',
                    ],
                ],
            ], 200),
        ]);

        app(UpdateSoldOffersUseCase::class)->executeFromGamivo();

        $row = DB::table('keys')->where('key_code', 'GAMIVO-KEY-TAX')->first();

        // profit = 2.00 + 0.50 - 0.01 = 2.49
        expect((float) $row->sold_price)->toEqualWithDelta(2.49, 0.001);
    });

    it('divides profit equally when an order has multiple keys', function () {
        insertUnsoldKey('MULTI-GAMIVO-001');
        insertUnsoldKey('MULTI-GAMIVO-002');

        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response([
                'count' => 1,
                'data' => [[
                    'order_id' => 'order-multi-001',
                    'profit' => 4.00,
                    'seller_tax' => 0.00,
                    'created_at' => '2025-05-06UTC10:00:000',
                ]],
            ], 200),
            '*/accounts/sales/history/25/25*' => Http::response(['count' => 0, 'data' => []], 200),
            '*/accounts/sales/order-details/order-multi-001*' => Http::response([
                'id' => 'order-multi-001',
                'keys' => [
                    '9003' => [
                        'keys' => [
                            ['type' => 'TEXT', 'key' => 'MULTI-GAMIVO-001'],
                            ['type' => 'TEXT', 'key' => 'MULTI-GAMIVO-002'],
                        ],
                        'rating' => '-',
                    ],
                ],
            ], 200),
        ]);

        app(UpdateSoldOffersUseCase::class)->executeFromGamivo();

        // profit total = 4.00 - 0.01 = 3.99; por key = 3.99 / 2 = 2.00 (arredondado)
        $key1 = DB::table('keys')->where('key_code', 'MULTI-GAMIVO-001')->first();
        $key2 = DB::table('keys')->where('key_code', 'MULTI-GAMIVO-002')->first();

        expect((float) $key1->sold_price)->toEqualWithDelta(2.00, 0.001)
            ->and((float) $key2->sold_price)->toEqualWithDelta(2.00, 0.001);
    });

    it('extracts the sale date from the non-standard created_at format', function () {
        insertUnsoldKey('GAMIVO-DATE-KEY');

        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response([
                'count' => 1,
                'data' => [[
                    'order_id' => 'order-date-001',
                    'profit' => 3.00,
                    'seller_tax' => 0.00,
                    'created_at' => '2025-04-13UTC17:44:480',
                ]],
            ], 200),
            '*/accounts/sales/history/25/25*' => Http::response(['count' => 0, 'data' => []], 200),
            '*/accounts/sales/order-details/order-date-001*' => Http::response([
                'id' => 'order-date-001',
                'keys' => [
                    '9004' => [
                        'keys' => [['type' => 'TEXT', 'key' => 'GAMIVO-DATE-KEY']],
                        'rating' => '-',
                    ],
                ],
            ], 200),
        ]);

        app(UpdateSoldOffersUseCase::class)->executeFromGamivo();

        $row = DB::table('keys')->where('key_code', 'GAMIVO-DATE-KEY')->first();

        expect($row->sold_at)->toBe('2025-04-13');
    });

    it('returns an empty array and logs when there are no sales in the period', function () {
        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);

        $result = app(UpdateSoldOffersUseCase::class)->executeFromGamivo();

        expect($result)->toBeEmpty();
    });

    it('skips a sale when order-details returns null', function () {
        insertUnsoldKey('GAMIVO-SKIP-KEY');

        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response([
                'count' => 1,
                'data' => [[
                    'order_id' => 'order-missing-001',
                    'profit' => 3.00,
                    'seller_tax' => 0.00,
                    'created_at' => '2025-05-06UTC10:00:000',
                ]],
            ], 200),
            '*/accounts/sales/history/25/25*' => Http::response(['count' => 0, 'data' => []], 200),
            // 404 → getSaleOrderDetails retorna null
            '*/accounts/sales/order-details/order-missing-001*' => Http::response('', 404),
        ]);

        app(UpdateSoldOffersUseCase::class)->executeFromGamivo();

        // Key não deve ter sido atualizada
        $row = DB::table('keys')->where('key_code', 'GAMIVO-SKIP-KEY')->first();
        expect($row->sold_at)->toBeNull();
    });
});
