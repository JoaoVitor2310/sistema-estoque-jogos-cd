<?php

/*
|--------------------------------------------------------------------------
| ListKeyForSale — characterization tests
|--------------------------------------------------------------------------
|
| Covers KeySaleController::insertDataVenda() → ListKeyForSaleUseCase
| Route: POST /keys/insert-data-venda  (no auth — withoutMiddleware)
| Response shape: { "statusCode": int, "message": "...", "data": [] }
|
*/

use App\Domain\Pricing\MinMaxPriceCalculator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedFks(): void
{
    DB::table('suppliers')->insertOrIgnore(['id' => 1, 'supplier_url' => 'https://steamcommunity.com/id/seed']);
}

function insertKey(string $keyCode, array $overrides = []): void
{
    DB::table('keys')->insert(array_merge([
        'game_name' => 'Test Game',
        'gamivo_id' => 'gam-'.uniqid(),
        'key_code' => $keyCode,
        'market_price' => 5.00,
        'individual_cost' => 2.00,
        'purchase_profit_percent' => 25.00,
        'supplier_url' => 'https://steamcommunity.com/id/test',
        'supplier_id' => 1,
        'claim_type' => 'Nenhuma',
        'key_format' => 'RK',
        'sell_platform' => 'Gamivo',
        'listed_at' => null,
        'sold_at' => null,
        'min_api' => 1.50,
        'max_api' => 10.00,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('POST /keys/insert-data-venda', function () {

    beforeEach(function () {
        Config::set('services.external_secret', 'test-secret');
        seedFks();
    });

    // ── Validation ────────────────────────────────────────────────────────────

    it('returns 404 when key_code is missing from the request', function () {
        $this->withToken('test-secret')->postJson('/keys/insert-data-venda', [])
            ->assertStatus(404);
    });

    it('returns 404 when key_code is empty string', function () {
        $this->withToken('test-secret')->postJson('/keys/insert-data-venda', ['key_code' => ''])
            ->assertStatus(404);
    });

    // ── Happy path ────────────────────────────────────────────────────────────

    it('sets listed_at to today and returns 200', function () {
        insertKey('AAAAA-11111-BBBBB');

        $this->withToken('test-secret')->postJson('/keys/insert-data-venda', [
            'key_code' => 'AAAAA-11111-BBBBB',
        ])->assertOk();

        $row = DB::table('keys')->where('key_code', 'AAAAA-11111-BBBBB')->first();

        expect($row->listed_at)->toBe(now()->toDateString());
    });

    it('resets min_api to FLOOR by default', function () {
        insertKey('BBBBB-22222-CCCCC');

        $this->withToken('test-secret')->postJson('/keys/insert-data-venda', [
            'key_code' => 'BBBBB-22222-CCCCC',
        ])->assertOk();

        $row = DB::table('keys')->where('key_code', 'BBBBB-22222-CCCCC')->first();

        expect((float) $row->min_api)->toBe(MinMaxPriceCalculator::FLOOR);
    });

    it('does NOT reset min_api when updateMinApiGamivo is false', function () {
        insertKey('CCCCC-33333-DDDDD', ['min_api' => 1.50]);

        $this->withToken('test-secret')->postJson('/keys/insert-data-venda', [
            'key_code' => 'CCCCC-33333-DDDDD',
            'updateMinApiGamivo' => false,
        ])->assertOk();

        $row = DB::table('keys')->where('key_code', 'CCCCC-33333-DDDDD')->first();

        // min_api não deve ter sido alterado
        expect((float) $row->min_api)->toBe(1.50);
    });

    // ── Exclusion rules ───────────────────────────────────────────────────────

    it('returns 404 when the key does not exist', function () {
        $this->withToken('test-secret')->postJson('/keys/insert-data-venda', [
            'key_code' => 'XXXXX-99999-YYYYY',
        ])->assertStatus(404);
    });

    it('returns 404 when the key already has a listed_at set', function () {
        insertKey('DDDDD-44444-EEEEE', ['listed_at' => now()->subDays(3)->toDateString()]);

        $this->withToken('test-secret')->postJson('/keys/insert-data-venda', [
            'key_code' => 'DDDDD-44444-EEEEE',
        ])->assertStatus(404);
    });

    it('only updates keys with listed_at null when multiple rows share the same keyCode', function () {
        // Dois registros com a mesma chave: um já listado, outro não.
        insertKey('EEEEE-55555-FFFFF', ['listed_at' => now()->subDays(5)->toDateString()]);
        insertKey('EEEEE-55555-FFFFF', ['listed_at' => null]);

        $this->withToken('test-secret')->postJson('/keys/insert-data-venda', [
            'key_code' => 'EEEEE-55555-FFFFF',
        ])->assertOk();

        $rows = DB::table('keys')->where('key_code', 'EEEEE-55555-FFFFF')->get();

        // Um registro tinha listed_at antes — deve permanecer inalterado (mesma data anterior)
        $alreadyListed = $rows->firstWhere('listed_at', now()->subDays(5)->toDateString());
        $newlyListed = $rows->firstWhere('listed_at', now()->toDateString());

        expect($alreadyListed)->not->toBeNull()
            ->and($newlyListed)->not->toBeNull();
    });
});
