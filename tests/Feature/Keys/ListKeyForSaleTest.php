<?php

/*
|--------------------------------------------------------------------------
| ListKeyForSale — characterization tests
|--------------------------------------------------------------------------
|
| Covers KeySaleController::insertDataVenda() → ListKeyForSaleUseCase
| Route: POST /venda-chave-troca/insert-data-venda  (no auth — withoutMiddleware)
| Response shape: { "statusCode": int, "message": "...", "data": [] }
|
*/

use App\Domain\Pricing\MinMaxPriceCalculator;
use Illuminate\Support\Facades\DB;

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedFks(): void
{
    DB::table('fornecedor')->insertOrIgnore(['id' => 1, 'supplier_url' => 'https://steamcommunity.com/id/seed']);
}

function insertKey(string $keyCode, array $overrides = []): void
{
    DB::table('venda_chave_trocas')->insert(array_merge([
        'game_name' => 'Test Game',
        'gamivo_id' => 'gam-'.uniqid(),
        'key_code' => $keyCode,
        'market_price' => 5.00,
        'individual_cost' => 2.00,
        'purchase_profit_percent' => 25.00,
        'supplier_url' => 'https://steamcommunity.com/id/test',
        'id_fornecedor' => 1,
        'claim_type' => 'Nenhuma',
        'key_format' => 'RK',
        'sell_platform' => 'Gamivo',
        'listed_at' => null,
        'sold_at' => null,
        'minApiGamivo' => 1.50,
        'maxApiGamivo' => 10.00,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('POST /venda-chave-troca/insert-data-venda', function () {

    beforeEach(fn () => seedFks());

    // ── Validation ────────────────────────────────────────────────────────────

    it('returns 404 when key_code is missing from the request', function () {
        $this->postJson('/venda-chave-troca/insert-data-venda', [])
            ->assertStatus(404);
    });

    it('returns 404 when key_code is empty string', function () {
        $this->postJson('/venda-chave-troca/insert-data-venda', ['key_code' => ''])
            ->assertStatus(404);
    });

    // ── Happy path ────────────────────────────────────────────────────────────

    it('sets listed_at to today and returns 200', function () {
        insertKey('AAAAA-11111-BBBBB');

        $this->postJson('/venda-chave-troca/insert-data-venda', [
            'key_code' => 'AAAAA-11111-BBBBB',
        ])->assertOk();

        $row = DB::table('venda_chave_trocas')->where('key_code', 'AAAAA-11111-BBBBB')->first();

        expect($row->listed_at)->toBe(now()->toDateString());
    });

    it('resets minApiGamivo to FLOOR by default', function () {
        insertKey('BBBBB-22222-CCCCC');

        $this->postJson('/venda-chave-troca/insert-data-venda', [
            'key_code' => 'BBBBB-22222-CCCCC',
        ])->assertOk();

        $row = DB::table('venda_chave_trocas')->where('key_code', 'BBBBB-22222-CCCCC')->first();

        expect((float) $row->minApiGamivo)->toBe(MinMaxPriceCalculator::FLOOR);
    });

    it('does NOT reset minApiGamivo when updateMinApiGamivo is false', function () {
        insertKey('CCCCC-33333-DDDDD', ['minApiGamivo' => 1.50]);

        $this->postJson('/venda-chave-troca/insert-data-venda', [
            'key_code' => 'CCCCC-33333-DDDDD',
            'updateMinApiGamivo' => false,
        ])->assertOk();

        $row = DB::table('venda_chave_trocas')->where('key_code', 'CCCCC-33333-DDDDD')->first();

        // minApiGamivo não deve ter sido alterado
        expect((float) $row->minApiGamivo)->toBe(1.50);
    });

    // ── Exclusion rules ───────────────────────────────────────────────────────

    it('returns 404 when the key does not exist', function () {
        $this->postJson('/venda-chave-troca/insert-data-venda', [
            'key_code' => 'XXXXX-99999-YYYYY',
        ])->assertStatus(404);
    });

    it('returns 404 when the key already has a listed_at set', function () {
        insertKey('DDDDD-44444-EEEEE', ['listed_at' => now()->subDays(3)->toDateString()]);

        $this->postJson('/venda-chave-troca/insert-data-venda', [
            'key_code' => 'DDDDD-44444-EEEEE',
        ])->assertStatus(404);
    });

    it('only updates keys with listed_at null when multiple rows share the same keyCode', function () {
        // Dois registros com a mesma chave: um já listado, outro não.
        insertKey('EEEEE-55555-FFFFF', ['listed_at' => now()->subDays(5)->toDateString()]);
        insertKey('EEEEE-55555-FFFFF', ['listed_at' => null]);

        $this->postJson('/venda-chave-troca/insert-data-venda', [
            'key_code' => 'EEEEE-55555-FFFFF',
        ])->assertOk();

        $rows = DB::table('venda_chave_trocas')->where('key_code', 'EEEEE-55555-FFFFF')->get();

        // Um registro tinha listed_at antes — deve permanecer inalterado (mesma data anterior)
        $alreadyListed = $rows->firstWhere('listed_at', now()->subDays(5)->toDateString());
        $newlyListed = $rows->firstWhere('listed_at', now()->toDateString());

        expect($alreadyListed)->not->toBeNull()
            ->and($newlyListed)->not->toBeNull();
    });
});
