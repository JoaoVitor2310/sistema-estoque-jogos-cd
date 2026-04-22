<?php

/*
|--------------------------------------------------------------------------
| RegisterKeyUseCase — characterization tests
|--------------------------------------------------------------------------
|
| Cobre o fluxo completo de registro de um lote de keys:
|   - Cálculos financeiros (income, custo individual, lucros, min/max)
|   - Detecção de chave duplicada (repetido)
|   - Identificação de plataforma
|   - Criação de fornecedor e jogo quando inexistentes
|   - Isolamento de erros: falha em uma key não interrompe o lote
|
| Taxas semeadas (padrão de produção):
|   gamivoPercentualMenor = 0.072  (7.2 %)
|   gamivoFixoMenor       = 0.110  (€ 0.11)
|   gamivoPercentualMaior = 0.102  (10.2 %)
|   gamivoFixoMaior       = 0.550  (€ 0.55)
|   TF2 preco_euro        = 2.000  (€ 2.00 por TF2 key)
|
*/

use App\Domain\Pricing\SalePriceCalculator;
use App\UseCases\Keys\RegisterKeyUseCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedRegisterFks(): void
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
}

/**
 * Monta o array de entrada de uma key no mesmo formato que o XLSX produz.
 * precoCliente = 5.00 → income ≈ 4.53 (tier baixo: 5×0.928 - 0.11)
 */
function makeGameInput(array $overrides = []): array
{
    return array_merge([
        'nomeJogo' => 'Test Game',
        'chaveRecebida' => 'AAAAA-11111-BBBBB',
        'perfilOrigem' => 'https://steamcommunity.com/id/seller',
        'qtdTF2' => 2.0,
        'precoCliente' => 5.00,
        'region' => null,
        'dataAdquirida' => now()->toDateString(),
        'idGamivo' => null,
        'steamId' => null,
        'precoJogo' => null,
        'notaMetacritic' => 0,
        'minimoParaVenda' => null,
        'minApiGamivo' => null,
        'maxApiGamivo' => null,
        'tipo_reclamacao_id' => 1,
        'tipo_formato_id' => 1,
        'id_leilao_g2a' => 1,
        'id_leilao_gamivo' => 1,
        'id_leilao_kinguin' => 1,
        'id_plataforma' => 1,
        'dataVenda' => null,
        'dataVendida' => null,
        'observacao' => null,
        'chaveEntregue' => null,
        'valorPagoTotal' => null,
        'vendido' => false,
        'leiloes' => 1,
        'quantidade' => 1,
        'devolucoes' => false,
        'valorVendido' => null,
        'email' => null,
        'isSteam' => false,
        'color' => null,
    ], $overrides);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('RegisterKeyUseCase', function () {

    beforeEach(function () {
        seedRegisterFks();
        Cache::flush(); // Evita cache de taxas e TF2 de outros testes
    });

    // ── Happy path ────────────────────────────────────────────────────────────

    it('persists a key and returns it in the result', function () {
        $result = app(RegisterKeyUseCase::class)->execute([makeGameInput()]);

        expect($result['games'])->toHaveCount(1)
            ->and($result['errors'])->toBeEmpty();
    });

    it('calculates incomeSimulado based on Gamivo fees', function () {
        // precoCliente = 5.00 → tier baixo: 5 × (1 - 0.072) - 0.11 = 4.53
        $result = app(RegisterKeyUseCase::class)->execute([makeGameInput()]);

        expect($result['games'][0]->incomeSimulado)->toEqualWithDelta(4.53, 0.01);
    });

    it('calculates valorPagoIndividual proportional to income share', function () {
        // Lote de 1 key: ratio = 1.0 → custo = 2.0 × 2.0 × 1.0 = 4.0
        $result = app(RegisterKeyUseCase::class)->execute([makeGameInput()]);

        expect((float) $result['games'][0]->valorPagoIndividual)->toEqualWithDelta(4.0, 0.01);
    });

    it('calculates minimoParaVenda as 1.05x precoCliente', function () {
        // 1.05 × 5.00 = 5.25
        $result = app(RegisterKeyUseCase::class)->execute([makeGameInput(['precoCliente' => 5.00])]);

        expect((float) $result['games'][0]->minimoParaVenda)
            ->toEqualWithDelta(SalePriceCalculator::minimumSalePrice(5.00), 0.001);
    });

    it('formats valorPagoTotal as "{qtdTF2}x TF2 Keys / {count}"', function () {
        $result = app(RegisterKeyUseCase::class)->execute([
            makeGameInput(['qtdTF2' => 3.5, 'chaveRecebida' => 'KEY-A-00001']),
            makeGameInput(['qtdTF2' => 3.5, 'chaveRecebida' => 'KEY-B-00002']),
        ]);

        // Lote de 2 keys — cada uma recebe o rótulo referente ao lote completo
        expect($result['games'][0]->valorPagoTotal)->toBe('3.5x TF2 Keys / 2')
            ->and($result['games'][1]->valorPagoTotal)->toBe('3.5x TF2 Keys / 2');
    });

    it('populates minApiGamivo and maxApiGamivo', function () {
        $result = app(RegisterKeyUseCase::class)->execute([makeGameInput()]);

        $game = $result['games'][0];
        expect((float) $game->minApiGamivo)->toBeGreaterThan(0)
            ->and((float) $game->maxApiGamivo)->toBeGreaterThan((float) $game->minApiGamivo);
    });

    // ── Duplicate detection ───────────────────────────────────────────────────

    it('marks repetido=true when the key code already exists in the database', function () {
        // Insere uma key com a mesma chave no banco antes do execute
        DB::table('venda_chave_trocas')->insert(array_merge(makeGameInput(), [
            'id_fornecedor' => DB::table('fornecedor')->insertGetId(['perfilOrigem' => 'https://steamcommunity.com/id/seed']),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $result = app(RegisterKeyUseCase::class)->execute([makeGameInput()]);

        expect($result['games'][0]->repetido)->toBeTrue();
    });

    it('does not mark repetido when the key code is unique', function () {
        $result = app(RegisterKeyUseCase::class)->execute([makeGameInput()]);

        expect($result['games'][0]->repetido)->toBeFalsy();
    });

    // ── Platform identification ───────────────────────────────────────────────

    it('identifies Steam platform from the 5-5-5 key format', function () {
        $result = app(RegisterKeyUseCase::class)->execute([
            makeGameInput(['chaveRecebida' => 'ABCDE-12345-FGHIJ']),
        ]);

        expect($result['games'][0]->plataformaIdentificada)->toBe('Steam');
    });

    it('sets plataformaIdentificada to DESCONHECIDO for unrecognized formats', function () {
        $result = app(RegisterKeyUseCase::class)->execute([
            makeGameInput(['chaveRecebida' => 'UNKNOWNFORMATKEY']),
        ]);

        expect($result['games'][0]->plataformaIdentificada)->toBe('DESCONHECIDO');
    });

    it('includes unidentified-platform count in the message', function () {
        $result = app(RegisterKeyUseCase::class)->execute([
            makeGameInput(['chaveRecebida' => 'UNKNOWNFORMATKEY']),
        ]);

        expect($result['message'])->toContain('plataforma não identificada');
    });

    // ── Supplier ──────────────────────────────────────────────────────────────

    it('creates the supplier if it does not exist', function () {
        $profile = 'https://steamcommunity.com/id/newvendor';
        app(RegisterKeyUseCase::class)->execute([makeGameInput(['perfilOrigem' => $profile])]);

        expect(DB::table('fornecedor')->where('perfilOrigem', $profile)->exists())->toBeTrue();
    });

    it('reuses the same supplier when two keys share the same perfilOrigem', function () {
        $profile = 'https://steamcommunity.com/id/sameSeller';

        app(RegisterKeyUseCase::class)->execute([
            makeGameInput(['chaveRecebida' => 'KEY-X-11111', 'perfilOrigem' => $profile]),
            makeGameInput(['chaveRecebida' => 'KEY-X-22222', 'perfilOrigem' => $profile]),
        ]);

        $keys = DB::table('venda_chave_trocas')
            ->whereIn('chaveRecebida', ['KEY-X-11111', 'KEY-X-22222'])
            ->pluck('id_fornecedor')
            ->unique();

        // Ambas as keys devem referenciar o mesmo fornecedor
        expect($keys)->toHaveCount(1);
    });

    // ── Game table ────────────────────────────────────────────────────────────

    it('creates a game record in the games table', function () {
        app(RegisterKeyUseCase::class)->execute([makeGameInput(['nomeJogo' => 'Brand New Game'])]);

        expect(DB::table('games')->where('name', 'Brand New Game')->exists())->toBeTrue();
    });

    it('does not duplicate the game when a record with the same name already exists (case-insensitive)', function () {
        // Jogo já existe com casing diferente
        DB::table('games')->insert(['name' => 'test game', 'region' => null, 'created_at' => now(), 'updated_at' => now()]);

        app(RegisterKeyUseCase::class)->execute([makeGameInput(['nomeJogo' => 'Test Game', 'region' => null])]);

        expect(DB::table('games')->whereRaw('LOWER("name") = ?', ['test game'])->count())->toBe(1);
    });

    it('propagates idGamivo to the games table when provided', function () {
        app(RegisterKeyUseCase::class)->execute([
            makeGameInput(['idGamivo' => 'gam-test-99', 'nomeJogo' => 'Game With Id']),
        ]);

        expect(DB::table('games')->where('id_gamivo', 'gam-test-99')->exists())->toBeTrue();
    });

    // ── Batch cost distribution ───────────────────────────────────────────────

    it('distributes cost proportionally across keys in the same batch', function () {
        // Dois jogos com preços diferentes num mesmo lote de 2.0 TF2 keys
        // income game1 = 5×0.928 - 0.11 ≈ 4.53 ; income game2 = 10×0.898 - 0.55 ≈ 8.43
        // somatorio ≈ 12.96 ; custo total do lote = 2.0 × 2.0 = 4.0
        $result = app(RegisterKeyUseCase::class)->execute([
            makeGameInput(['chaveRecebida' => 'BATCH-KEY-001', 'precoCliente' => 5.00]),
            makeGameInput(['chaveRecebida' => 'BATCH-KEY-002', 'precoCliente' => 10.00]),
        ]);

        $cost1 = (float) $result['games'][0]->valorPagoIndividual;
        $cost2 = (float) $result['games'][1]->valorPagoIndividual;

        // O jogo mais caro (income maior) deve ter custo individual maior
        expect($cost2)->toBeGreaterThan($cost1);
    });

    // ── Error isolation ───────────────────────────────────────────────────────

    it('collects errors per key without interrupting the remaining batch', function () {
        $validGame = makeGameInput(['chaveRecebida' => 'VALID-KEY-001']);
        $invalidGame = makeGameInput([
            'chaveRecebida' => 'VALID-KEY-002',
            'tipo_reclamacao_id' => 9999, // FK inválido → viola constraint no banco
        ]);

        $result = app(RegisterKeyUseCase::class)->execute([$validGame, $invalidGame]);

        expect($result['games'])->toHaveCount(1)
            ->and($result['errors'])->toHaveCount(1)
            ->and($result['errors'][0]['linha'])->toBe(2);
    });

    it('includes error count in the message when errors occur', function () {
        $invalidGame = makeGameInput([
            'tipo_reclamacao_id' => 9999,
        ]);

        $result = app(RegisterKeyUseCase::class)->execute([$invalidGame]);

        expect($result['message'])->toContain('erro');
    });

    it('returns an empty errors list when all keys succeed', function () {
        $result = app(RegisterKeyUseCase::class)->execute([makeGameInput()]);

        expect($result['errors'])->toBeEmpty();
    });
});
