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
|   - Criação do jogo quando inexistente e vínculo com o fornecedor da trade
|   - Isolamento de erros: falha em uma key não interrompe o lote
|   - Recusa do lote quando a trade não está pronta para importar
|
| O lote sai da trade gravada, então o seed é uma trade com linhas
| (`Tests\Support\TradeFactory`) em vez de um array de entrada.
|
| Taxas semeadas (padrão de produção):
|   gamivo_percent_low  = 0.060  (6.0 %)
|   gamivo_fixed_low    = 0.250  (€ 0.25)
|   gamivo_percent_high = 0.080  (8.0 %)
|   gamivo_fixed_high   = 0.400  (€ 0.40)
|   TF2 price_euro      = 2.000  (€ 2.00 por TF2 key)
|
*/

use App\Models\Key;
use App\Models\Trade;
use App\UseCases\Keys\RegisterKeyUseCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\TradeFactory;

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

const REGISTER_SUPPLIER_URL = 'https://steamcommunity.com/id/seller';

/**
 * Uma linha da trade.
 * market_price = 5.00 → income ≈ 4.45 (tier baixo: 5×0.940 - 0.250)
 */
function makeLine(array $overrides = []): array
{
    return array_merge([
        'game_name' => 'Test Game',
        'key_code' => 'AAAAA-11111-BBBBB',
        'market_price' => 5.00,
        'region' => null,
    ], $overrides);
}

/**
 * Trade de origem do lote — pronta para importar por padrão.
 *
 * @param  list<array<string, mixed>>|null  $lines
 */
function newTrade(?array $lines = null, array $attrs = []): Trade
{
    $supplierId = DB::table('suppliers')->insertGetId([
        'url' => $attrs['supplier_url'] ?? REGISTER_SUPPLIER_URL,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    unset($attrs['supplier_url']);

    return TradeFactory::withLines($lines ?? [makeLine()], array_merge([
        'supplier_id' => $supplierId,
        'tf2_qty' => 2.0,
        'date' => now()->toDateString(),
    ], $attrs));
}

/**
 * Faz a key de um `key_code` explodir na gravação, como uma falha de banco.
 *
 * Substitui o antigo enum inválido em `claim_type`: esse campo vem do
 * `KeyDefaults`, não da linha da trade, e deixou de ser alcançável pelo seed.
 * O listener some junto com o container do teste, sem vazar para o seguinte.
 */
function failKeyOnCreate(string $keyCode): void
{
    Key::creating(function (Key $key) use ($keyCode) {
        if ($key->key_code === $keyCode) {
            throw new RuntimeException('falha simulada de gravação');
        }
    });
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('RegisterKeyUseCase', function () {

    beforeEach(function () {
        seedRegisterFks();
        Cache::flush(); // Evita cache de taxas e TF2 de outros testes
    });

    // ── Happy path ────────────────────────────────────────────────────────────

    it('persists a key and returns it in the result', function () {
        $result = app(RegisterKeyUseCase::class)->execute(newTrade());

        expect($result['games'])->toHaveCount(1)
            ->and($result['errors'])->toBeEmpty();
    });

    it('links every registered key to the originating trade', function () {
        $trade = newTrade();

        $result = app(RegisterKeyUseCase::class)->execute($trade);

        expect((int) $result['games'][0]->trade_id)->toBe($trade->id);
    });

    // ── O lote sai da trade, não de um payload ────────────────────────────────

    it('reads market price, key code, region and gamivo_id from the stored line', function () {
        $trade = newTrade([makeLine([
            'game_name' => 'Stored Game',
            'key_code' => 'STORED-KEY-001',
            'market_price' => 7.25,
            'region' => 'EU',
            'gamivo_id' => '144601',
        ])]);

        $key = app(RegisterKeyUseCase::class)->execute($trade)['games'][0];

        expect($key->game_name)->toBe('Stored Game')
            ->and($key->key_code)->toBe('STORED-KEY-001')
            ->and((float) $key->market_price)->toEqualWithDelta(7.25, 0.01)
            ->and($key->region)->toBe('EU')
            ->and($key->gamivo_id)->toBe('144601');
    });

    it('reads acquired_at and tf2_quantity from the trade itself', function () {
        $trade = newTrade(null, ['date' => '2026-03-04', 'tf2_qty' => 3.5]);

        $key = app(RegisterKeyUseCase::class)->execute($trade)['games'][0];

        expect($key->acquired_at)->toBe('2026-03-04')
            ->and((float) $key->tf2_quantity)->toEqualWithDelta(3.5, 0.01);
    });

    it('carries the line expiry date over to the key', function () {
        $trade = newTrade([makeLine(['expires_at' => '2027-01-31'])]);

        expect(app(RegisterKeyUseCase::class)->execute($trade)['games'][0]->expires_at)
            ->toBe('2027-01-31');
    });

    it('skips blank lines instead of importing empty keys', function () {
        $trade = newTrade([
            makeLine(),
            ['game_name' => null, 'market_price' => null, 'key_code' => null],
        ]);

        expect(app(RegisterKeyUseCase::class)->execute($trade)['games'])->toHaveCount(1);
    });

    // ── Prontidão para importar ───────────────────────────────────────────────

    it('refuses the batch when a filled line has no key code', function () {
        $trade = newTrade([makeLine(['key_code' => null])]);

        $result = app(RegisterKeyUseCase::class)->execute($trade);

        expect($result['games'])->toBeEmpty()
            ->and($result['message'])->toContain('sem key code')
            ->and(DB::table('keys')->count())->toBe(0)
            ->and($trade->fresh()->is_imported)->toBeFalse();
    });

    it('refuses the batch when the trade has no TF2 quantity', function () {
        $trade = newTrade(null, ['tf2_qty' => null]);

        $result = app(RegisterKeyUseCase::class)->execute($trade);

        expect($result['games'])->toBeEmpty()
            ->and($result['message'])->toContain('quantidade de TF2')
            ->and(DB::table('keys')->count())->toBe(0);
    });

    it('refuses the batch when the trade has no supplier', function () {
        $trade = TradeFactory::withLines([makeLine()], ['tf2_qty' => 2.0, 'date' => now()->toDateString()]);

        $result = app(RegisterKeyUseCase::class)->execute($trade);

        expect($result['games'])->toBeEmpty()
            ->and($result['message'])->toContain('fornecedor');
    });

    it('refuses the batch when the trade has no filled line', function () {
        $trade = newTrade([['game_name' => null, 'market_price' => null, 'key_code' => null]]);

        $result = app(RegisterKeyUseCase::class)->execute($trade);

        expect($result['games'])->toBeEmpty()
            ->and($result['message'])->toContain('nenhuma linha preenchida');
    });

    // ── Ciclo de vida da trade ────────────────────────────────────────────────

    it('marks the trade as imported when the batch has no errors', function () {
        $trade = newTrade();

        app(RegisterKeyUseCase::class)->execute($trade);

        expect($trade->fresh()->is_imported)->toBeTrue();
    });

    it('does not mark the trade as imported when a key fails', function () {
        failKeyOnCreate('BAD-KEY-0002');

        $trade = newTrade([
            makeLine(['key_code' => 'OK-KEY-00001']),
            makeLine(['key_code' => 'BAD-KEY-0002']),
        ]);

        $result = app(RegisterKeyUseCase::class)->execute($trade);

        expect($result['errors'])->toHaveCount(1)
            ->and($trade->fresh()->is_imported)->toBeFalse();
    });

    it('calculates simulated_income based on Gamivo fees', function () {
        // market_price = 5.00 → tier baixo: 5 × (1 - 0.060) - 0.250 = 4.45
        $result = app(RegisterKeyUseCase::class)->execute(newTrade());

        expect($result['games'][0]->simulated_income)->toEqualWithDelta(4.45, 0.01);
    });

    it('calculates individual_cost proportional to income share', function () {
        // Lote de 1 key: ratio = 1.0 → custo = 2.0 × 2.0 × 1.0 = 4.0
        $result = app(RegisterKeyUseCase::class)->execute(newTrade());

        expect((float) $result['games'][0]->individual_cost)->toEqualWithDelta(4.0, 0.01);
    });

    it('formats total_paid as "{tf2_quantity}x TF2 Keys / {count}"', function () {
        $trade = newTrade([
            makeLine(['key_code' => 'KEY-A-00001']),
            makeLine(['key_code' => 'KEY-B-00002']),
        ], ['tf2_qty' => 3.5]);

        $result = app(RegisterKeyUseCase::class)->execute($trade);

        // Lote de 2 keys — cada uma recebe o rótulo referente ao lote completo
        expect($result['games'][0]->total_paid)->toBe('3.5x TF2 Keys / 2')
            ->and($result['games'][1]->total_paid)->toBe('3.5x TF2 Keys / 2');
    });

    it('populates min_api and max_api', function () {
        $result = app(RegisterKeyUseCase::class)->execute(newTrade());

        $game = $result['games'][0];
        expect((float) $game->min_api)->toBeGreaterThan(0)
            ->and((float) $game->max_api)->toBeGreaterThan((float) $game->min_api);
    });

    // ── Duplicate detection ───────────────────────────────────────────────────

    it('marks is_duplicate=true when the key code already exists in the database', function () {
        // Insere uma key com a mesma chave no banco antes do execute
        DB::table('keys')->insert([
            'game_name' => 'Test Game',
            'key_code' => 'AAAAA-11111-BBBBB',
            'supplier_url' => REGISTER_SUPPLIER_URL,
            'market_price' => 5.00,
            'min_api' => 1.00,
            'max_api' => 10.00,
            'supplier_id' => DB::table('suppliers')->insertGetId(['url' => 'https://steamcommunity.com/id/seed']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(RegisterKeyUseCase::class)->execute(newTrade());

        expect($result['games'][0]->is_duplicate)->toBeTrue();
    });

    it('does not mark is_duplicate when the key code is unique', function () {
        $result = app(RegisterKeyUseCase::class)->execute(newTrade());

        expect($result['games'][0]->is_duplicate)->toBeFalsy();
    });

    // ── Platform identification ───────────────────────────────────────────────

    it('identifies Steam platform from the 5-5-5 key format', function () {
        $trade = newTrade([makeLine(['key_code' => 'ABCDE-12345-FGHIJ'])]);

        $result = app(RegisterKeyUseCase::class)->execute($trade);

        expect($result['games'][0]->identified_platform)->toBe('Steam');
    });

    it('sets identified_platform to DESCONHECIDO for unrecognized formats', function () {
        $trade = newTrade([makeLine(['key_code' => 'UNKNOWNFORMATKEY'])]);

        $result = app(RegisterKeyUseCase::class)->execute($trade);

        expect($result['games'][0]->identified_platform)->toBe('DESCONHECIDO');
    });

    it('includes unidentified-platform count in the message', function () {
        $trade = newTrade([makeLine(['key_code' => 'UNKNOWNFORMATKEY'])]);

        $result = app(RegisterKeyUseCase::class)->execute($trade);

        expect($result['message'])->toContain('plataforma não identificada');
    });

    // ── Supplier ──────────────────────────────────────────────────────────────

    it('links the key to the supplier of the trade', function () {
        $profile = 'https://steamcommunity.com/id/vendor';
        $trade = newTrade(null, ['supplier_url' => $profile]);

        $key = app(RegisterKeyUseCase::class)->execute($trade)['games'][0];

        expect((int) $key->supplier_id)->toBe((int) $trade->supplier_id)
            ->and($key->supplier_url)->toBe($profile);
    });

    it('gives every key of the batch the same supplier', function () {
        $trade = newTrade([
            makeLine(['key_code' => 'KEY-X-11111']),
            makeLine(['key_code' => 'KEY-X-22222']),
        ]);

        app(RegisterKeyUseCase::class)->execute($trade);

        $suppliers = DB::table('keys')
            ->whereIn('key_code', ['KEY-X-11111', 'KEY-X-22222'])
            ->pluck('supplier_id')
            ->unique();

        // Ambas as keys devem referenciar o mesmo fornecedor
        expect($suppliers)->toHaveCount(1);
    });

    // ── Game table ────────────────────────────────────────────────────────────

    it('creates a game record in the games table', function () {
        app(RegisterKeyUseCase::class)->execute(newTrade([makeLine(['game_name' => 'Brand New Game'])]));

        expect(DB::table('games')->where('name', 'Brand New Game')->exists())->toBeTrue();
    });

    it('does not duplicate the game when a record with the same name already exists (case-insensitive)', function () {
        // Jogo já existe com casing diferente
        DB::table('games')->insert(['name' => 'test game', 'region' => null, 'created_at' => now(), 'updated_at' => now()]);

        app(RegisterKeyUseCase::class)->execute(newTrade([makeLine(['game_name' => 'Test Game', 'region' => null])]));

        expect(DB::table('games')->whereRaw('LOWER("name") = ?', ['test game'])->count())->toBe(1);
    });

    it('propagates gamivo_id to the games table when provided', function () {
        $trade = newTrade([makeLine(['gamivo_id' => 'gam-test-99', 'game_name' => 'Game With Id'])]);

        app(RegisterKeyUseCase::class)->execute($trade);

        expect(DB::table('games')->where('gamivo_id', 'gam-test-99')->exists())->toBeTrue();
    });

    // ── Batch cost distribution ───────────────────────────────────────────────

    it('distributes cost proportionally across keys in the same batch', function () {
        // Dois jogos com preços diferentes num mesmo lote de 2.0 TF2 keys
        // income game1 = 5×0.940 - 0.250 = 4.45 ; income game2 = 10×0.920 - 0.400 = 8.80
        // somatorio = 13.25 ; custo total do lote = 2.0 × 2.0 = 4.0
        $trade = newTrade([
            makeLine(['key_code' => 'BATCH-KEY-001', 'market_price' => 5.00]),
            makeLine(['key_code' => 'BATCH-KEY-002', 'market_price' => 10.00]),
        ]);

        $result = app(RegisterKeyUseCase::class)->execute($trade);

        $cost1 = (float) $result['games'][0]->individual_cost;
        $cost2 = (float) $result['games'][1]->individual_cost;

        // O jogo mais caro (income maior) deve ter custo individual maior
        expect($cost2)->toBeGreaterThan($cost1);
    });

    // ── Atomicidade: tudo ou nada ─────────────────────────────────────────────

    it('persists no key at all when one of them fails', function () {
        failKeyOnCreate('VALID-KEY-002');

        $trade = newTrade([
            makeLine(['key_code' => 'VALID-KEY-001']),
            makeLine(['key_code' => 'VALID-KEY-002']),
        ]);

        $result = app(RegisterKeyUseCase::class)->execute($trade);

        // A key válida também é descartada — nada é meio-importado
        expect($result['games'])->toBeEmpty();
        expect(DB::table('keys')->count())->toBe(0);
    });

    it('reports every failing line of the batch at once', function () {
        // Duas linhas ruins → ambos os erros voltam juntos, evitando reimportar em partes
        failKeyOnCreate('BAD-KEY-00002');
        failKeyOnCreate('BAD-KEY-00003');

        $trade = newTrade([
            makeLine(['key_code' => 'OK-KEY-000001']),
            makeLine(['key_code' => 'BAD-KEY-00002']),
            makeLine(['key_code' => 'BAD-KEY-00003']),
        ]);

        $result = app(RegisterKeyUseCase::class)->execute($trade);

        expect($result['errors'])->toHaveCount(2)
            ->and(array_column($result['errors'], 'line'))->toBe([2, 3]);
    });

    it('rolls back the side effects on games when the batch fails', function () {
        failKeyOnCreate('BAD-KEY-00001');

        $trade = newTrade([
            makeLine(['key_code' => 'BAD-KEY-00001', 'game_name' => 'Jogo Que Nao Deve Existir']),
        ]);

        $result = app(RegisterKeyUseCase::class)->execute($trade);

        expect($result['errors'])->toHaveCount(1);
        expect(DB::table('games')->where('name', 'Jogo Que Nao Deve Existir')->exists())->toBeFalse();
    });

    it('states that nothing was registered in the message when errors occur', function () {
        failKeyOnCreate('AAAAA-11111-BBBBB');

        $result = app(RegisterKeyUseCase::class)->execute(newTrade());

        expect($result['message'])->toContain('Nenhuma key foi cadastrada');
    });

    it('returns an empty errors list when all keys succeed', function () {
        $result = app(RegisterKeyUseCase::class)->execute(newTrade());

        expect($result['errors'])->toBeEmpty();
    });
});
