<?php

/*
|--------------------------------------------------------------------------
| RegisterKeyUseCase — characterization tests
|--------------------------------------------------------------------------
|
| Cobre o fluxo completo de registro de um lote de keys:
|   - Cálculos financeiros (income, custo individual, lucros, min/max)
|   - Detecção de chave duplicada (is_duplicate)
|   - Identificação de plataforma
|   - Criação de fornecedor e jogo quando inexistentes
|   - Isolamento de erros: falha em uma key não interrompe o lote
|
| Taxas semeadas (padrão de produção):
|   gamivo_percent_low  = 0.060  (6.0 %)
|   gamivo_fixed_low    = 0.250  (€ 0.25)
|   gamivo_percent_high = 0.080  (8.0 %)
|   gamivo_fixed_high   = 0.400  (€ 0.40)
|   TF2 price_euro      = 2.000  (€ 2.00 por TF2 key)
|
*/

use App\Models\Trade;
use App\UseCases\Keys\RegisterKeyUseCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedRegisterFks(): void
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
}

/** Trade de origem do lote — obrigatória em todo registro de key. */
function newTrade(): Trade
{
    return Trade::create(['games' => []]);
}

/**
 * Monta o array de entrada de uma key.
 * market_price = 5.00 → income ≈ 4.45 (tier baixo: 5×0.940 - 0.250)
 */
function makeGameInput(array $overrides = []): array
{
    return array_merge([
        'game_name' => 'Test Game',
        'key_code' => 'AAAAA-11111-BBBBB',
        'supplier_url' => 'https://steamcommunity.com/id/seller',
        'tf2_quantity' => 2.0,
        'market_price' => 5.00,
        'region' => null,
        'acquired_at' => now()->toDateString(),
        'gamivo_id' => null,
        'steam_id' => null,
        'min_api' => null,
        'max_api' => null,
        'claim_type' => 'Nenhuma',
        'key_format' => 'RK',
        'sell_platform' => 'Gamivo',
        'listed_at' => null,
        'sold_at' => null,
        'notes' => null,
        'total_paid' => null,
        'sold_price' => null,
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
        $result = app(RegisterKeyUseCase::class)->execute(newTrade(), [makeGameInput()]);

        expect($result['games'])->toHaveCount(1)
            ->and($result['errors'])->toBeEmpty();
    });

    it('links every registered key to the originating trade', function () {
        $trade = Trade::create(['games' => []]);

        $result = app(RegisterKeyUseCase::class)->execute($trade, [makeGameInput()]);

        expect((int) $result['games'][0]->trade_id)->toBe($trade->id);
    });

    // ── Ciclo de vida da trade ────────────────────────────────────────────────

    it('marks the trade as imported when the batch has no errors', function () {
        $trade = Trade::create(['games' => []]);

        app(RegisterKeyUseCase::class)->execute($trade, [makeGameInput()]);

        expect($trade->fresh()->is_imported)->toBeTrue();
    });

    it('does not mark the trade as imported when a key fails', function () {
        $trade = Trade::create(['games' => []]);

        // claim_type inválido → ValueError no cast do Eloquent → erro coletado no lote
        $result = app(RegisterKeyUseCase::class)->execute($trade, [
            makeGameInput(['key_code' => 'OK-KEY-00001']),
            makeGameInput(['key_code' => 'BAD-KEY-0002', 'claim_type' => 'INVALID_ENUM']),
        ]);

        expect($result['errors'])->toHaveCount(1)
            ->and($trade->fresh()->is_imported)->toBeFalse();
    });

    it('calculates simulated_income based on Gamivo fees', function () {
        // market_price = 5.00 → tier baixo: 5 × (1 - 0.060) - 0.250 = 4.45
        $result = app(RegisterKeyUseCase::class)->execute(newTrade(), [makeGameInput()]);

        expect($result['games'][0]->simulated_income)->toEqualWithDelta(4.45, 0.01);
    });

    it('calculates individual_cost proportional to income share', function () {
        // Lote de 1 key: ratio = 1.0 → custo = 2.0 × 2.0 × 1.0 = 4.0
        $result = app(RegisterKeyUseCase::class)->execute(newTrade(), [makeGameInput()]);

        expect((float) $result['games'][0]->individual_cost)->toEqualWithDelta(4.0, 0.01);
    });

    it('formats total_paid as "{tf2_quantity}x TF2 Keys / {count}"', function () {
        $result = app(RegisterKeyUseCase::class)->execute(newTrade(), [
            makeGameInput(['tf2_quantity' => 3.5, 'key_code' => 'KEY-A-00001']),
            makeGameInput(['tf2_quantity' => 3.5, 'key_code' => 'KEY-B-00002']),
        ]);

        // Lote de 2 keys — cada uma recebe o rótulo referente ao lote completo
        expect($result['games'][0]->total_paid)->toBe('3.5x TF2 Keys / 2')
            ->and($result['games'][1]->total_paid)->toBe('3.5x TF2 Keys / 2');
    });

    it('populates min_api and max_api', function () {
        $result = app(RegisterKeyUseCase::class)->execute(newTrade(), [makeGameInput()]);

        $game = $result['games'][0];
        expect((float) $game->min_api)->toBeGreaterThan(0)
            ->and((float) $game->max_api)->toBeGreaterThan((float) $game->min_api);
    });

    // ── Duplicate detection ───────────────────────────────────────────────────

    it('marks is_duplicate=true when the key code already exists in the database', function () {
        // Insere uma key com a mesma chave no banco antes do execute
        DB::table('keys')->insert(array_merge(makeGameInput(), [
            'supplier_id' => DB::table('suppliers')->insertGetId(['url' => 'https://steamcommunity.com/id/seed']),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $result = app(RegisterKeyUseCase::class)->execute(newTrade(), [makeGameInput()]);

        expect($result['games'][0]->is_duplicate)->toBeTrue();
    });

    it('does not mark is_duplicate when the key code is unique', function () {
        $result = app(RegisterKeyUseCase::class)->execute(newTrade(), [makeGameInput()]);

        expect($result['games'][0]->is_duplicate)->toBeFalsy();
    });

    // ── Platform identification ───────────────────────────────────────────────

    it('identifies Steam platform from the 5-5-5 key format', function () {
        $result = app(RegisterKeyUseCase::class)->execute(newTrade(), [
            makeGameInput(['key_code' => 'ABCDE-12345-FGHIJ']),
        ]);

        expect($result['games'][0]->identified_platform)->toBe('Steam');
    });

    it('sets identified_platform to DESCONHECIDO for unrecognized formats', function () {
        $result = app(RegisterKeyUseCase::class)->execute(newTrade(), [
            makeGameInput(['key_code' => 'UNKNOWNFORMATKEY']),
        ]);

        expect($result['games'][0]->identified_platform)->toBe('DESCONHECIDO');
    });

    it('includes unidentified-platform count in the message', function () {
        $result = app(RegisterKeyUseCase::class)->execute(newTrade(), [
            makeGameInput(['key_code' => 'UNKNOWNFORMATKEY']),
        ]);

        expect($result['message'])->toContain('plataforma não identificada');
    });

    // ── Supplier ──────────────────────────────────────────────────────────────

    it('creates the supplier if it does not exist', function () {
        $profile = 'https://steamcommunity.com/id/newvendor';
        app(RegisterKeyUseCase::class)->execute(newTrade(), [makeGameInput(['supplier_url' => $profile])]);

        expect(DB::table('suppliers')->where('url', $profile)->exists())->toBeTrue();
    });

    it('reuses the same supplier when two keys share the same supplier_url', function () {
        $profile = 'https://steamcommunity.com/id/sameSeller';

        app(RegisterKeyUseCase::class)->execute(newTrade(), [
            makeGameInput(['key_code' => 'KEY-X-11111', 'supplier_url' => $profile]),
            makeGameInput(['key_code' => 'KEY-X-22222', 'supplier_url' => $profile]),
        ]);

        $keys = DB::table('keys')
            ->whereIn('key_code', ['KEY-X-11111', 'KEY-X-22222'])
            ->pluck('supplier_id')
            ->unique();

        // Ambas as keys devem referenciar o mesmo fornecedor
        expect($keys)->toHaveCount(1);
    });

    // ── Game table ────────────────────────────────────────────────────────────

    it('creates a game record in the games table', function () {
        app(RegisterKeyUseCase::class)->execute(newTrade(), [makeGameInput(['game_name' => 'Brand New Game'])]);

        expect(DB::table('games')->where('name', 'Brand New Game')->exists())->toBeTrue();
    });

    it('does not duplicate the game when a record with the same name already exists (case-insensitive)', function () {
        // Jogo já existe com casing diferente
        DB::table('games')->insert(['name' => 'test game', 'region' => null, 'created_at' => now(), 'updated_at' => now()]);

        app(RegisterKeyUseCase::class)->execute(newTrade(), [makeGameInput(['game_name' => 'Test Game', 'region' => null])]);

        expect(DB::table('games')->whereRaw('LOWER("name") = ?', ['test game'])->count())->toBe(1);
    });

    it('propagates gamivo_id to the games table when provided', function () {
        app(RegisterKeyUseCase::class)->execute(newTrade(), [
            makeGameInput(['gamivo_id' => 'gam-test-99', 'game_name' => 'Game With Id']),
        ]);

        expect(DB::table('games')->where('gamivo_id', 'gam-test-99')->exists())->toBeTrue();
    });

    // ── Batch cost distribution ───────────────────────────────────────────────

    it('distributes cost proportionally across keys in the same batch', function () {
        // Dois jogos com preços diferentes num mesmo lote de 2.0 TF2 keys
        // income game1 = 5×0.940 - 0.250 = 4.45 ; income game2 = 10×0.920 - 0.400 = 8.80
        // somatorio = 13.25 ; custo total do lote = 2.0 × 2.0 = 4.0
        $result = app(RegisterKeyUseCase::class)->execute(newTrade(), [
            makeGameInput(['key_code' => 'BATCH-KEY-001', 'market_price' => 5.00]),
            makeGameInput(['key_code' => 'BATCH-KEY-002', 'market_price' => 10.00]),
        ]);

        $cost1 = (float) $result['games'][0]->individual_cost;
        $cost2 = (float) $result['games'][1]->individual_cost;

        // O jogo mais caro (income maior) deve ter custo individual maior
        expect($cost2)->toBeGreaterThan($cost1);
    });

    // ── Atomicidade: tudo ou nada ─────────────────────────────────────────────

    it('persists no key at all when one of them fails', function () {
        $validGame = makeGameInput(['key_code' => 'VALID-KEY-001']);
        $invalidGame = makeGameInput([
            'key_code' => 'VALID-KEY-002',
            'claim_type' => 'INVALID_ENUM', // Valor inválido → ValueError ao fazer cast pelo Eloquent
        ]);

        $result = app(RegisterKeyUseCase::class)->execute(newTrade(), [$validGame, $invalidGame]);

        // A key válida também é descartada — nada é meio-importado
        expect($result['games'])->toBeEmpty();
        expect(DB::table('keys')->count())->toBe(0);
    });

    it('reports every failing line of the batch at once', function () {
        // Duas linhas ruins → ambos os erros voltam juntos, evitando reimportar em partes
        $result = app(RegisterKeyUseCase::class)->execute(newTrade(), [
            makeGameInput(['key_code' => 'OK-KEY-000001']),
            makeGameInput(['key_code' => 'BAD-KEY-00002', 'claim_type' => 'INVALID_ENUM']),
            makeGameInput(['key_code' => 'BAD-KEY-00003', 'key_format' => 'INVALID_ENUM']),
        ]);

        expect($result['errors'])->toHaveCount(2)
            ->and(array_column($result['errors'], 'line'))->toBe([2, 3]);
    });

    it('rolls back the side effects on games and suppliers when the batch fails', function () {
        $result = app(RegisterKeyUseCase::class)->execute(newTrade(), [
            makeGameInput([
                'key_code' => 'BAD-KEY-00001',
                'game_name' => 'Jogo Que Nao Deve Existir',
                'supplier_url' => 'https://steamcommunity.com/id/naoDeveExistir',
                'claim_type' => 'INVALID_ENUM',
            ]),
        ]);

        expect($result['errors'])->toHaveCount(1);
        expect(DB::table('games')->where('name', 'Jogo Que Nao Deve Existir')->exists())->toBeFalse();
        expect(DB::table('suppliers')->where('url', 'https://steamcommunity.com/id/naoDeveExistir')->exists())->toBeFalse();
    });

    it('states that nothing was registered in the message when errors occur', function () {
        $invalidGame = makeGameInput([
            'claim_type' => 'INVALID_ENUM', // Valor inválido → ValueError ao fazer cast pelo Eloquent
        ]);

        $result = app(RegisterKeyUseCase::class)->execute(newTrade(), [$invalidGame]);

        expect($result['message'])->toContain('Nenhuma key foi cadastrada');
    });

    it('returns an empty errors list when all keys succeed', function () {
        $result = app(RegisterKeyUseCase::class)->execute(newTrade(), [makeGameInput()]);

        expect($result['errors'])->toBeEmpty();
    });
});
