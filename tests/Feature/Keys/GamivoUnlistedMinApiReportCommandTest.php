<?php

/*
|--------------------------------------------------------------------------
| gamivo:unlisted-min-api-report — feature tests
|--------------------------------------------------------------------------
|
| Comando de diagnóstico read-only: reproduz a decisão de listagem do
| AutoSellUseCase::processGroup() (mesma chamada ao ComparisonAlgorithm,
| detectDumpers: false, requireOurOffer: false) para keys ainda não listadas,
| sem nunca chamar createOffer/uploadKeys. Todos os requests Gamivo são
| interceptados via Http::fake().
|
*/

use App\Models\Fee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedUnlistedReportFees(): void
{
    Fee::upsert([
        ['name' => 'gamivo_percent_low', 'preco' => 0.060],
        ['name' => 'gamivo_fixed_low', 'preco' => 0.250],
        ['name' => 'gamivo_percent_high', 'preco' => 0.080],
        ['name' => 'gamivo_fixed_high', 'preco' => 0.400],
    ], uniqueBy: ['name'], update: ['preco']);
}

function insertUnlistedReportKey(int $gamivoId, array $overrides = []): void
{
    DB::table('suppliers')->insertOrIgnore([
        'id' => 77,
        'url' => 'https://steamcommunity.com/id/unlisted-report-test',
    ]);

    DB::table('keys')->insert(array_merge([
        'game_name' => "Game {$gamivoId}",
        'gamivo_id' => (string) $gamivoId,
        'key_code' => 'KEY-UNLISTED-'.uniqid(),
        'market_price' => 5.00,
        'individual_cost' => 5.00,
        'min_api' => 7.50,
        'max_api' => 40.00,
        'purchase_profit_percent' => 25.00,
        'supplier_url' => 'https://steamcommunity.com/id/unlisted-report-test',
        'supplier_id' => 77,
        'claim_type' => 'Nenhuma',
        'key_format' => 'RK',
        'sell_platform' => 'Gamivo',
        'acquired_at' => now()->toDateString(),
        'listed_at' => null,
        'sold_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('gamivo:unlisted-min-api-report', function () {

    beforeEach(function () {
        seedUnlistedReportFees();
        $this->jsonPath = storage_path('framework/testing/unlisted-min-api-report-test.json');
    });

    afterEach(function () {
        if (file_exists($this->jsonPath)) {
            unlink($this->jsonPath);
        }
    });

    it('is not blocked and would list at max_api when there are no competitors yet', function () {
        insertUnlistedReportKey(555, ['min_api' => 3.00, 'max_api' => 20.00]);

        Http::fake([
            '*/products/555/offers' => Http::response([], 200),
        ]);

        $this->artisan('gamivo:unlisted-min-api-report', ['--json' => $this->jsonPath, '--delay-ms' => 0])
            ->assertExitCode(0);

        $rows = json_decode(file_get_contents($this->jsonPath), true);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['no_competitors'])->toBeTrue()
            ->and($rows[0]['is_blocked'])->toBeFalse()
            ->and($rows[0]['would_list_at'])->toBe(20.0);
    });

    it('flags a key as blocked when the market price falls short of its min_api', function () {
        // Único concorrente a 6.00 → target = 6.00 - 0.014 = 5.986 → seller_price ≈ 5.38,
        // abaixo do min_api de 7.50 (custo 5.00 × 1.50, tier default 50%).
        insertUnlistedReportKey(666, ['individual_cost' => 5.00, 'min_api' => 7.50, 'max_api' => 40.00]);

        Http::fake([
            '*/products/666/offers' => Http::response([
                ['id' => 1, 'seller_name' => 'Rival', 'retail_price' => 6.00, 'completed_orders' => 5, 'wholesale_mode' => 0],
            ], 200),
        ]);

        $this->artisan('gamivo:unlisted-min-api-report', ['--json' => $this->jsonPath, '--delay-ms' => 0])
            ->assertExitCode(0);

        $rows = json_decode(file_get_contents($this->jsonPath), true);

        expect($rows[0]['no_competitors'])->toBeFalse()
            ->and($rows[0]['is_blocked'])->toBeTrue()
            ->and($rows[0]['market_price'])->toBeLessThan(7.50)
            ->and($rows[0]['gap_below_floor'])->toBeGreaterThan(0)
            ->and($rows[0]['would_list_at'])->toBeNull();
    });

    it('classifies the margin bucket by acquired_at age (unlisted branch), not cost', function () {
        // Acquired 5 meses atrás → cai em UNLISTED_MODERATE_MONTHS (>= 4, < 6) → 40%, não cost tier.
        insertUnlistedReportKey(777, [
            'individual_cost' => 5.00,
            'min_api' => 7.00, // 5.00 × 1.40
            'max_api' => 40.00,
            'acquired_at' => now()->subMonths(5)->toDateString(),
        ]);

        Http::fake([
            '*/products/777/offers' => Http::response([], 200),
        ]);

        $this->artisan('gamivo:unlisted-min-api-report', ['--json' => $this->jsonPath, '--delay-ms' => 0])
            ->assertExitCode(0);

        $rows = json_decode(file_get_contents($this->jsonPath), true);

        expect($rows[0]['margin_bucket'])->toBe('age >=4m unlisted (40%)');
    });

    it('classifies a young key of a direct purchase in the bundle store bucket, not the cost tier', function () {
        $tradeId = DB::table('trades')->insertGetId(['purchase_channel' => 'bundle_store', 'created_at' => now(), 'updated_at' => now()]);
        insertUnlistedReportKey(779, ['trade_id' => $tradeId, 'min_api' => 7.00]);

        Http::fake([
            '*/products/779/offers' => Http::response([], 200),
        ]);

        $this->artisan('gamivo:unlisted-min-api-report', ['--json' => $this->jsonPath, '--delay-ms' => 0])
            ->assertExitCode(0);

        $rows = json_decode(file_get_contents($this->jsonPath), true);

        expect($rows[0]['margin_bucket'])->toBe('bundle store (40%)');
    });

    it('groups multiple keys of the same gamivo_id into a single market query', function () {
        insertUnlistedReportKey(888, ['key_code' => 'KEY-888-A', 'min_api' => 1.00]);
        insertUnlistedReportKey(888, ['key_code' => 'KEY-888-B', 'min_api' => 1.00]);

        Http::fake([
            '*/products/888/offers' => Http::response([], 200),
        ]);

        $this->artisan('gamivo:unlisted-min-api-report', ['--json' => $this->jsonPath, '--delay-ms' => 0])
            ->assertExitCode(0);

        $rows = json_decode(file_get_contents($this->jsonPath), true);

        expect($rows)->toHaveCount(2);
        Http::assertSentCount(1);
    });
});
