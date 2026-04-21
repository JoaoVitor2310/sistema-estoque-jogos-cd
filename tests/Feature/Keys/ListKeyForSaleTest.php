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
    DB::table('tipo_reclamacao')->insertOrIgnore(['id' => 1, 'name' => 'Nenhuma']);
    DB::table('tipo_formato')->insertOrIgnore(['id' => 1, 'name' => 'Key']);
    DB::table('tipo_leilao')->insertOrIgnore(['id' => 1, 'name' => 'Fixo']);
    DB::table('plataforma')->insertOrIgnore(['id' => 1, 'name' => 'Gamivo']);
    DB::table('fornecedor')->insertOrIgnore(['id' => 1, 'perfilOrigem' => 'https://steamcommunity.com/id/seed']);
}

function insertKey(string $keyCode, array $overrides = []): void
{
    DB::table('venda_chave_trocas')->insert(array_merge([
        'nomeJogo'            => 'Test Game',
        'idGamivo'            => 'gam-' . uniqid(),
        'chaveRecebida'       => $keyCode,
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
        'minApiGamivo'        => 1.50,
        'maxApiGamivo'        => 10.00,
        'created_at'          => now(),
        'updated_at'          => now(),
    ], $overrides));
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('POST /venda-chave-troca/insert-data-venda', function () {

    beforeEach(fn () => seedFks());

    // ── Validation ────────────────────────────────────────────────────────────

    it('returns 404 when chaveRecebida is missing from the request', function () {
        $this->postJson('/venda-chave-troca/insert-data-venda', [])
            ->assertStatus(404);
    });

    it('returns 404 when chaveRecebida is empty string', function () {
        $this->postJson('/venda-chave-troca/insert-data-venda', ['chaveRecebida' => ''])
            ->assertStatus(404);
    });

    // ── Happy path ────────────────────────────────────────────────────────────

    it('sets dataVenda to today and returns 200', function () {
        insertKey('AAAAA-11111-BBBBB');

        $this->postJson('/venda-chave-troca/insert-data-venda', [
            'chaveRecebida' => 'AAAAA-11111-BBBBB',
        ])->assertOk();

        $row = DB::table('venda_chave_trocas')->where('chaveRecebida', 'AAAAA-11111-BBBBB')->first();

        expect($row->dataVenda)->toBe(now()->toDateString());
    });

    it('resets minApiGamivo to FLOOR by default', function () {
        insertKey('BBBBB-22222-CCCCC');

        $this->postJson('/venda-chave-troca/insert-data-venda', [
            'chaveRecebida' => 'BBBBB-22222-CCCCC',
        ])->assertOk();

        $row = DB::table('venda_chave_trocas')->where('chaveRecebida', 'BBBBB-22222-CCCCC')->first();

        expect((float) $row->minApiGamivo)->toBe(MinMaxPriceCalculator::FLOOR);
    });

    it('does NOT reset minApiGamivo when updateMinApiGamivo is false', function () {
        insertKey('CCCCC-33333-DDDDD', ['minApiGamivo' => 1.50]);

        $this->postJson('/venda-chave-troca/insert-data-venda', [
            'chaveRecebida'      => 'CCCCC-33333-DDDDD',
            'updateMinApiGamivo' => false,
        ])->assertOk();

        $row = DB::table('venda_chave_trocas')->where('chaveRecebida', 'CCCCC-33333-DDDDD')->first();

        // minApiGamivo não deve ter sido alterado
        expect((float) $row->minApiGamivo)->toBe(1.50);
    });

    // ── Exclusion rules ───────────────────────────────────────────────────────

    it('returns 404 when the key does not exist', function () {
        $this->postJson('/venda-chave-troca/insert-data-venda', [
            'chaveRecebida' => 'XXXXX-99999-YYYYY',
        ])->assertStatus(404);
    });

    it('returns 404 when the key already has a dataVenda set', function () {
        insertKey('DDDDD-44444-EEEEE', ['dataVenda' => now()->subDays(3)->toDateString()]);

        $this->postJson('/venda-chave-troca/insert-data-venda', [
            'chaveRecebida' => 'DDDDD-44444-EEEEE',
        ])->assertStatus(404);
    });

    it('only updates keys with dataVenda null when multiple rows share the same keyCode', function () {
        // Dois registros com a mesma chave: um já listado, outro não.
        insertKey('EEEEE-55555-FFFFF', ['dataVenda' => now()->subDays(5)->toDateString()]);
        insertKey('EEEEE-55555-FFFFF', ['dataVenda' => null]);

        $this->postJson('/venda-chave-troca/insert-data-venda', [
            'chaveRecebida' => 'EEEEE-55555-FFFFF',
        ])->assertOk();

        $rows = DB::table('venda_chave_trocas')->where('chaveRecebida', 'EEEEE-55555-FFFFF')->get();

        // Um registro tinha dataVenda antes — deve permanecer inalterado (mesma data anterior)
        $alreadyListed = $rows->firstWhere('dataVenda', now()->subDays(5)->toDateString());
        $newlyListed   = $rows->firstWhere('dataVenda', now()->toDateString());

        expect($alreadyListed)->not->toBeNull()
            ->and($newlyListed)->not->toBeNull();
    });
});
