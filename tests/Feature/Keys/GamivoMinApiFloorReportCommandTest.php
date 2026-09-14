<?php

/*
|--------------------------------------------------------------------------
| gamivo:min-api-floor-report — feature tests
|--------------------------------------------------------------------------
|
| Comando de diagnóstico read-only: simula o ComparisonAlgorithm sem o clamp
| de min_api/max_api para medir o gap entre o preço natural de mercado e o
| min_api praticado. Todos os requests Gamivo são interceptados via Http::fake().
|
*/

use App\Models\Fee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedFloorReportFees(): void
{
    Fee::upsert([
        ['name' => 'gamivo_percent_low', 'preco' => 0.060],
        ['name' => 'gamivo_fixed_low', 'preco' => 0.250],
        ['name' => 'gamivo_percent_high', 'preco' => 0.080],
        ['name' => 'gamivo_fixed_high', 'preco' => 0.400],
    ], uniqueBy: ['name'], update: ['preco']);
}

function insertFloorReportGoverningKey(int $gamivoId, float $minApi, float $maxApi, ?int $tradeId = null): void
{
    DB::table('suppliers')->insertOrIgnore([
        'id' => 88,
        'url' => 'https://steamcommunity.com/id/floor-report-test',
    ]);

    DB::table('keys')->insertOrIgnore([
        'game_name' => "Game {$gamivoId}",
        'gamivo_id' => (string) $gamivoId,
        'key_code' => "KEY-FLOOR-{$gamivoId}",
        'market_price' => 5.00,
        'individual_cost' => 2.00,
        'min_api' => $minApi,
        'max_api' => $maxApi,
        'purchase_profit_percent' => 25.00,
        'supplier_url' => 'https://steamcommunity.com/id/floor-report-test',
        'supplier_id' => 88,
        'claim_type' => 'Nenhuma',
        'key_format' => 'RK',
        'sell_platform' => 'Gamivo',
        'listed_at' => now()->toDateString(),
        'sold_at' => null,
        'trade_id' => $tradeId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('gamivo:min-api-floor-report', function () {

    beforeEach(function () {
        seedFloorReportFees();
        $this->jsonPath = storage_path('framework/testing/min-api-floor-report-test.json');
    });

    afterEach(function () {
        if (file_exists($this->jsonPath)) {
            unlink($this->jsonPath);
        }
    });

    it('flags an offer as floor-bound when the natural price falls below min_api', function () {
        // Somos os mais baratos (index 0); 2º colocado a 1.05 → target = 1.05 - 0.014 = 1.036
        // → seller_price natural ≈ 0.72, bem abaixo do min_api de 3.00.
        insertFloorReportGoverningKey(440, minApi: 3.00, maxApi: 20.00);

        Http::fake([
            '*/api/public/v1/offers*' => Http::response([
                ['product_id' => 440, 'status' => 1, 'seller_price' => 3.00, 'retail_price' => 3.50],
            ], 200),
            '*/products/440/offers' => Http::response([
                ['id' => 1, 'seller_name' => 'CarcaDeals', 'retail_price' => 1.00, 'completed_orders' => 5, 'wholesale_mode' => 0],
                ['id' => 2, 'seller_name' => 'Rival', 'retail_price' => 1.05, 'completed_orders' => 5, 'wholesale_mode' => 0],
            ], 200),
        ]);

        $this->artisan('gamivo:min-api-floor-report', ['--json' => $this->jsonPath, '--delay-ms' => 0])
            ->assertExitCode(0);

        $rows = json_decode(file_get_contents($this->jsonPath), true);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['gamivo_id'])->toBe(440)
            ->and($rows[0]['is_floor_bound'])->toBeTrue()
            ->and($rows[0]['min_api'])->toBe(3.00)
            ->and($rows[0]['natural_seller_price'])->toBeLessThan(3.00)
            ->and($rows[0]['gap_below_floor'])->toBeGreaterThan(0);
    });

    it('labels the initial margin of a direct purchase as bundle store, not a cost tier', function () {
        $tradeId = DB::table('trades')->insertGetId(['purchase_channel' => 'bundle_store', 'created_at' => now(), 'updated_at' => now()]);
        insertFloorReportGoverningKey(442, minApi: 2.80, maxApi: 20.00, tradeId: $tradeId);

        Http::fake([
            '*/api/public/v1/offers*' => Http::response([
                ['product_id' => 442, 'status' => 1, 'seller_price' => 3.00, 'retail_price' => 3.50],
            ], 200),
            '*/products/442/offers' => Http::response([
                ['id' => 1, 'seller_name' => 'CarcaDeals', 'retail_price' => 1.00, 'completed_orders' => 5, 'wholesale_mode' => 0],
                ['id' => 2, 'seller_name' => 'Rival', 'retail_price' => 1.05, 'completed_orders' => 5, 'wholesale_mode' => 0],
            ], 200),
        ]);

        $this->artisan('gamivo:min-api-floor-report', ['--json' => $this->jsonPath, '--delay-ms' => 0])
            ->assertExitCode(0);

        $rows = json_decode(file_get_contents($this->jsonPath), true);

        expect($rows[0]['cost_tier'])->toBe('bundle_store (40%)');
    });

    it('does not flag an offer when the market already clears min_api', function () {
        insertFloorReportGoverningKey(441, minApi: 0.50, maxApi: 20.00);

        Http::fake([
            '*/api/public/v1/offers*' => Http::response([
                ['product_id' => 441, 'status' => 1, 'seller_price' => 3.00, 'retail_price' => 3.50],
            ], 200),
            '*/products/441/offers' => Http::response([
                ['id' => 1, 'seller_name' => 'CarcaDeals', 'retail_price' => 1.00, 'completed_orders' => 5, 'wholesale_mode' => 0],
                ['id' => 2, 'seller_name' => 'Rival', 'retail_price' => 1.05, 'completed_orders' => 5, 'wholesale_mode' => 0],
            ], 200),
        ]);

        $this->artisan('gamivo:min-api-floor-report', ['--json' => $this->jsonPath, '--delay-ms' => 0])
            ->assertExitCode(0);

        $rows = json_decode(file_get_contents($this->jsonPath), true);

        expect($rows[0]['is_floor_bound'])->toBeFalse()
            ->and($rows[0]['gap_below_floor'])->toBe(0.0);
    });

    it('warns but does not fail when an active offer has no matching governing key', function () {
        Http::fake([
            '*/api/public/v1/offers*' => Http::response([
                ['product_id' => 999, 'status' => 1, 'seller_price' => 3.00, 'retail_price' => 3.50],
            ], 200),
        ]);

        $this->artisan('gamivo:min-api-floor-report', ['--json' => $this->jsonPath, '--delay-ms' => 0])
            ->expectsOutputToContain('sem key governante')
            ->assertExitCode(0);
    });

    it('ignores hardcoded product IDs (1767 — Random Game bundle)', function () {
        Http::fake([
            '*/api/public/v1/offers*' => Http::response([
                ['product_id' => 1767, 'status' => 1, 'seller_price' => 3.00, 'retail_price' => 3.50],
            ], 200),
        ]);

        $this->artisan('gamivo:min-api-floor-report', ['--json' => $this->jsonPath, '--delay-ms' => 0])
            ->assertExitCode(0);

        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'products/1767/offers'));
    });
});
