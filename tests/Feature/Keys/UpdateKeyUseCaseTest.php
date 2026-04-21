<?php

/*
|--------------------------------------------------------------------------
| UpdateKeyUseCase — characterization tests
|--------------------------------------------------------------------------
|
| Cobre as diferenças críticas em relação ao RegisterKeyUseCase:
|   - valorPagoIndividual NUNCA é recalculado no update (custo fixado na compra)
|   - qtdTF2 é preservado do banco se ausente no input
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

    DB::table('tipo_reclamacao')->insert(['id' => 1, 'name' => 'Nenhuma']);
    DB::table('tipo_formato')->insert(['id' => 1, 'name' => 'Key']);
    DB::table('tipo_leilao')->insert(['id' => 1, 'name' => 'Fixo']);
    DB::table('plataforma')->insert(['id' => 1, 'name' => 'Gamivo']);
    DB::table('fornecedor')->insert(['id' => 1, 'perfilOrigem' => 'https://steamcommunity.com/id/seed']);
}

/**
 * Insere uma key diretamente no banco e retorna o ID gerado.
 */
function insertKeyForUpdate(array $overrides = []): int
{
    return DB::table('venda_chave_trocas')->insertGetId(array_merge([
        'nomeJogo'            => 'Original Game',
        'chaveRecebida'       => 'ORIG-KEY-00001',
        'precoCliente'        => 5.00,
        'valorPagoIndividual' => 3.50, // Custo fixado na compra
        'qtdTF2'              => 2.5,
        'lucroPercentual'     => 25.00,
        'perfilOrigem'        => 'https://steamcommunity.com/id/seed',
        'id_fornecedor'       => 1,
        'tipo_reclamacao_id'  => 1,
        'tipo_formato_id'     => 1,
        'id_leilao_g2a'       => 1,
        'id_leilao_gamivo'    => 1,
        'id_leilao_kinguin'   => 1,
        'id_plataforma'       => 1,
        'created_at'          => now(),
        'updated_at'          => now(),
    ], $overrides));
}

/**
 * Input mínimo válido para o execute() do UpdateKeyUseCase.
 */
function makeUpdateInput(array $overrides = []): array
{
    return array_merge([
        'nomeJogo'            => 'Updated Game',
        'chaveRecebida'       => 'ORIG-KEY-00001',
        'perfilOrigem'        => 'https://steamcommunity.com/id/seed',
        'precoCliente'        => 6.00,
        'region'              => null,
        'tipo_reclamacao_id'  => 1,
        'tipo_formato_id'     => 1,
        'id_leilao_g2a'       => 1,
        'id_leilao_gamivo'    => 1,
        'id_leilao_kinguin'   => 1,
        'id_plataforma'       => 1,
        'dataAdquirida'       => now()->toDateString(),
        'idGamivo'            => null,
        'valorVendido'        => null,
    ], $overrides);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('UpdateKeyUseCase', function () {

    beforeEach(function () {
        seedUpdateFks();
        Cache::flush();
    });

    // ── Core invariant ────────────────────────────────────────────────────────

    it('preserves valorPagoIndividual from the database — never recalculated on update', function () {
        // Regra central: custo de compra é imutável após o registro.
        // Mesmo que precoCliente ou qtdTF2 mudem no update, o custo permanece.
        $id = insertKeyForUpdate(['valorPagoIndividual' => 3.50]);

        app(UpdateKeyUseCase::class)->execute((string) $id, makeUpdateInput(['precoCliente' => 9.99]));

        $row = DB::table('venda_chave_trocas')->where('id', $id)->first();

        expect((float) $row->valorPagoIndividual)->toBe(3.50);
    });

    it('preserves qtdTF2 from the database when not provided in the input', function () {
        $id = insertKeyForUpdate(['qtdTF2' => 2.5]);

        // Input sem qtdTF2 → deve puxar do banco
        $input = makeUpdateInput();
        unset($input['qtdTF2']);

        app(UpdateKeyUseCase::class)->execute((string) $id, $input);

        $row = DB::table('venda_chave_trocas')->where('id', $id)->first();

        expect((float) $row->qtdTF2)->toBe(2.5);
    });

    // ── Duplicate detection ───────────────────────────────────────────────────

    it('marks repetido=true when another key already has the same chaveRecebida', function () {
        // Duas keys distintas — ao atualizar key2 com o código de key1, deve marcar como duplicada
        insertKeyForUpdate(['chaveRecebida' => 'EXISTING-CODE-001']);
        $id2 = insertKeyForUpdate(['chaveRecebida' => 'DIFFERENT-CODE-002']);

        app(UpdateKeyUseCase::class)->execute(
            (string) $id2,
            makeUpdateInput(['chaveRecebida' => 'EXISTING-CODE-001'])
        );

        $row = DB::table('venda_chave_trocas')->where('id', $id2)->first();

        expect((bool) $row->repetido)->toBeTrue();
    });

    it('does not mark repetido when the key code belongs to the same record being updated (self-reference)', function () {
        // Editar uma key mantendo o mesmo chaveRecebida não deve marcá-la como duplicada
        $id = insertKeyForUpdate(['chaveRecebida' => 'MY-OWN-CODE-001']);

        app(UpdateKeyUseCase::class)->execute(
            (string) $id,
            makeUpdateInput(['chaveRecebida' => 'MY-OWN-CODE-001'])
        );

        $row = DB::table('venda_chave_trocas')->where('id', $id)->first();

        expect((bool) $row->repetido)->toBeFalsy();
    });

    // ── Platform identification ───────────────────────────────────────────────

    it('identifies the platform from the key format on update', function () {
        $id = insertKeyForUpdate(['chaveRecebida' => 'STEAM-11111-XXXXX']);

        app(UpdateKeyUseCase::class)->execute(
            (string) $id,
            makeUpdateInput(['chaveRecebida' => 'STEAM-11111-XXXXX'])
        );

        $row = DB::table('venda_chave_trocas')->where('id', $id)->first();

        expect($row->plataformaIdentificada)->toBe('Steam');
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
            ->and($result->relationLoaded('fornecedor'))->toBeTrue()
            ->and($result->relationLoaded('tipoReclamacao'))->toBeTrue();
    });
});
