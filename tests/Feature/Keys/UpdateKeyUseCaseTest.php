<?php

/*
|--------------------------------------------------------------------------
| UpdateKeyUseCase — characterization tests
|--------------------------------------------------------------------------
|
| Cobre as diferenças críticas em relação ao RegisterKeyUseCase:
|   - individual_cost NUNCA é recalculado no update (custo fixado na compra)
|   - tf2_quantity é preservado do banco se ausente no input
|   - Detecção de duplicidade exclui o próprio registro (excludeId)
|   - ModelNotFoundException quando o ID não existe
|
*/

use App\Models\Venda_chave_troca;
use App\UseCases\Keys\UpdateKeyUseCase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedUpdateFks(): void
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

    DB::table('fornecedor')->insert(['id' => 1, 'supplier_url' => 'https://steamcommunity.com/id/seed']);
}

/**
 * Insere uma key diretamente no banco e retorna o ID gerado.
 */
function insertKeyForUpdate(array $overrides = []): int
{
    return DB::table('venda_chave_trocas')->insertGetId(array_merge([
        'game_name' => 'Original Game',
        'key_code' => 'ORIG-KEY-00001',
        'market_price' => 5.00,
        'individual_cost' => 3.50, // Custo fixado na compra
        'tf2_quantity' => 2.5,
        'purchase_profit_percent' => 25.00,
        'supplier_url' => 'https://steamcommunity.com/id/seed',
        'id_fornecedor' => 1,
        'claim_type' => 'Nenhuma',
        'key_format' => 'RK',
        'sell_platform' => 'Gamivo',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * Input mínimo válido para o execute() do UpdateKeyUseCase.
 */
function makeUpdateInput(array $overrides = []): array
{
    return array_merge([
        'game_name' => 'Updated Game',
        'key_code' => 'ORIG-KEY-00001',
        'supplier_url' => 'https://steamcommunity.com/id/seed',
        'market_price' => 6.00,
        'region' => null,
        'claim_type' => 'Nenhuma',
        'key_format' => 'RK',
        'sell_platform' => 'Gamivo',
        'acquired_at' => now()->toDateString(),
        'gamivo_id' => null,
        'sold_price' => null,
    ], $overrides);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('UpdateKeyUseCase', function () {

    beforeEach(function () {
        seedUpdateFks();
        Cache::flush();
    });

    // ── Core invariant ────────────────────────────────────────────────────────

    it('preserves individual_cost from the database — never recalculated on update', function () {
        // Regra central: custo de compra é imutável após o registro.
        // Mesmo que market_price ou tf2_quantity mudem no update, o custo permanece.
        $id = insertKeyForUpdate(['individual_cost' => 3.50]);

        app(UpdateKeyUseCase::class)->execute((string) $id, makeUpdateInput(['market_price' => 9.99]));

        $row = DB::table('venda_chave_trocas')->where('id', $id)->first();

        expect((float) $row->individual_cost)->toBe(3.50);
    });

    it('preserves tf2_quantity from the database when not provided in the input', function () {
        $id = insertKeyForUpdate(['tf2_quantity' => 2.5]);

        // Input sem tf2_quantity → deve puxar do banco
        $input = makeUpdateInput();
        unset($input['tf2_quantity']);

        app(UpdateKeyUseCase::class)->execute((string) $id, $input);

        $row = DB::table('venda_chave_trocas')->where('id', $id)->first();

        expect((float) $row->tf2_quantity)->toBe(2.5);
    });

    // ── Duplicate detection ───────────────────────────────────────────────────

    it('marks is_duplicate=true when another key already has the same key_code', function () {
        // Duas keys distintas — ao atualizar key2 com o código de key1, deve marcar como duplicada
        insertKeyForUpdate(['key_code' => 'EXISTING-CODE-001']);
        $id2 = insertKeyForUpdate(['key_code' => 'DIFFERENT-CODE-002']);

        app(UpdateKeyUseCase::class)->execute(
            (string) $id2,
            makeUpdateInput(['key_code' => 'EXISTING-CODE-001'])
        );

        $row = DB::table('venda_chave_trocas')->where('id', $id2)->first();

        expect((bool) $row->is_duplicate)->toBeTrue();
    });

    it('does not mark is_duplicate when the key code belongs to the same record being updated (self-reference)', function () {
        // Editar uma key mantendo o mesmo key_code não deve marcá-la como duplicada
        $id = insertKeyForUpdate(['key_code' => 'MY-OWN-CODE-001']);

        app(UpdateKeyUseCase::class)->execute(
            (string) $id,
            makeUpdateInput(['key_code' => 'MY-OWN-CODE-001'])
        );

        $row = DB::table('venda_chave_trocas')->where('id', $id)->first();

        expect((bool) $row->is_duplicate)->toBeFalsy();
    });

    // ── Platform identification ───────────────────────────────────────────────

    it('identifies the platform from the key format on update', function () {
        $id = insertKeyForUpdate(['key_code' => 'STEAM-11111-XXXXX']);

        app(UpdateKeyUseCase::class)->execute(
            (string) $id,
            makeUpdateInput(['key_code' => 'STEAM-11111-XXXXX'])
        );

        $row = DB::table('venda_chave_trocas')->where('id', $id)->first();

        expect($row->identified_platform)->toBe('Steam');
    });

    // ── Error handling ────────────────────────────────────────────────────────

    it('throws ModelNotFoundException when the key ID does not exist', function () {
        expect(fn () => app(UpdateKeyUseCase::class)->execute('999999', makeUpdateInput()))
            ->toThrow(ModelNotFoundException::class);
    });

    // ── Return value ──────────────────────────────────────────────────────────

    it('returns the updated Venda_chave_troca model with relationships loaded', function () {
        $id = insertKeyForUpdate();

        $result = app(UpdateKeyUseCase::class)->execute((string) $id, makeUpdateInput());

        expect($result)->toBeInstanceOf(Venda_chave_troca::class)
            ->and($result->relationLoaded('fornecedor'))->toBeTrue();
    });
});
