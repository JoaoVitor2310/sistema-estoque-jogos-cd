<?php

/*
|--------------------------------------------------------------------------
| ImportTradeKeysTest — POST /trades/{trade}/import
|--------------------------------------------------------------------------
|
| A rota não recebe corpo: o lote sai das linhas gravadas da trade.
|
| Casos testados:
|
|   1. o lote é montado a partir das linhas do banco, sem corpo nenhum
|   2. um corpo enviado à rota é ignorado — o navegador não dita o market_price
|   3. gamivo_id da linha é persistido na key e propagado para a tabela games
|   4. gamivo_id ausente não quebra a importação (campo nullable)
|   5. as keys importadas são vinculadas à trade (trade_id)
|   6. importação sem erros marca a trade como is_imported
|   7. reimportação de uma trade já marcada como is_imported é aceita
|   8. lote recusado quando falta key_code numa linha preenchida, ou TF2 na trade
|   9. visitante não autorizado não importa
|  10. compra direta importa sem fornecedor; sem bundle, é recusada (demais
|      canais em tests/Feature/Keys/RegisterKeyUseCaseTest.php)
|
*/

use App\Models\AuthorizedUsers;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\TradeFactory;

function seedImportFees(): void
{
    DB::table('fees')->insert([
        ['name' => 'gamivo_percent_low', 'preco' => 0.060, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_low', 'preco' => 0.250, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_percent_high', 'preco' => 0.080, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_high', 'preco' => 0.400, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('assets')->insert([
        ['name' => 'TF2', 'price_euro' => 2.0, 'price_dollar' => 2.2, 'price_brl' => 10.0, 'created_at' => now(), 'updated_at' => now()],
    ]);
}

function makeAuthorizedImportUser(): User
{
    $user = User::factory()->create();
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    return $user;
}

/** Linha pronta para importar; os overrides trocam só o que o caso exige. */
function importLine(array $overrides = []): array
{
    return array_merge([
        'game_name' => 'Half-Life',
        'market_price' => 4.50,
        'key_code' => 'AAAAA-BBBBB-CCCCC',
        'region' => null,
    ], $overrides);
}

/**
 * Trade importável: com fornecedor, data e quantidade de TF2.
 *
 * @param  list<array<string, mixed>>|null  $lines
 */
function importableTrade(?array $lines = null, array $attrs = []): App\Models\Trade
{
    $supplierId = DB::table('suppliers')->insertGetId([
        'url' => 'https://steamcommunity.com/id/exemplo',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return TradeFactory::withLines($lines ?? [importLine()], array_merge([
        'supplier_id' => $supplierId,
        'tf2_qty' => 1.5,
        'date' => now()->toDateString(),
    ], $attrs));
}

describe('POST /trades/{trade}/import', function () {

    beforeEach(fn () => seedImportFees());

    // ── O lote sai do banco ───────────────────────────────────────────────────

    it('imports the stored lines without any request body', function () {
        $trade = importableTrade();

        $this->actingAs(makeAuthorizedImportUser())
            ->postJson(route('trades.import', ['trade' => $trade->id]))
            ->assertStatus(201)
            ->assertJsonPath('count', 1);

        $this->assertDatabaseHas('keys', [
            'key_code' => 'AAAAA-BBBBB-CCCCC',
            'game_name' => 'Half-Life',
            'trade_id' => $trade->id,
        ]);
    });

    it('ignores a market price sent in the body and uses the stored one', function () {
        $trade = importableTrade([importLine(['market_price' => 4.50])]);

        $this->actingAs(makeAuthorizedImportUser())
            ->postJson(route('trades.import', ['trade' => $trade->id]), [
                'games' => [['key_code' => 'AAAAA-BBBBB-CCCCC', 'market_price' => 999.00]],
            ])
            ->assertStatus(201);

        expect((float) DB::table('keys')->where('key_code', 'AAAAA-BBBBB-CCCCC')->value('market_price'))
            ->toEqualWithDelta(4.50, 0.01);
    });

    it('does not import blank lines kept as drafts in the tab', function () {
        $trade = importableTrade([
            importLine(),
            ['game_name' => null, 'market_price' => null, 'key_code' => null],
        ]);

        $this->actingAs(makeAuthorizedImportUser())
            ->postJson(route('trades.import', ['trade' => $trade->id]))
            ->assertStatus(201)
            ->assertJsonPath('count', 1);

        expect(DB::table('keys')->count())->toBe(1);
    });

    // ── gamivo_id ─────────────────────────────────────────────────────────────

    it('persists gamivo_id on the created key', function () {
        $trade = importableTrade([importLine(['gamivo_id' => '144601'])]);

        $this->actingAs(makeAuthorizedImportUser())
            ->postJson(route('trades.import', ['trade' => $trade->id]))
            ->assertStatus(201);

        $this->assertDatabaseHas('keys', [
            'key_code' => 'AAAAA-BBBBB-CCCCC',
            'gamivo_id' => '144601',
        ]);
    });

    it('propagates gamivo_id to the games table', function () {
        $trade = importableTrade([importLine(['gamivo_id' => '144601', 'game_name' => 'Propagated Game'])]);

        $this->actingAs(makeAuthorizedImportUser())
            ->postJson(route('trades.import', ['trade' => $trade->id]))
            ->assertStatus(201);

        expect(DB::table('games')->where('gamivo_id', '144601')->exists())->toBeTrue();
    });

    it('imports successfully when gamivo_id is not provided', function () {
        $trade = importableTrade();

        $this->actingAs(makeAuthorizedImportUser())
            ->postJson(route('trades.import', ['trade' => $trade->id]))
            ->assertStatus(201);

        $this->assertDatabaseHas('keys', [
            'key_code' => 'AAAAA-BBBBB-CCCCC',
            'gamivo_id' => null,
        ]);
    });

    // ── Ciclo de vida da trade ────────────────────────────────────────────────

    it('marks the trade as imported after a successful import', function () {
        $trade = importableTrade();

        // default do banco (o modelo recém-criado em memória não reflete o default)
        expect($trade->fresh()->is_imported)->toBeFalse();

        $this->actingAs(makeAuthorizedImportUser())
            ->postJson(route('trades.import', ['trade' => $trade->id]))
            ->assertStatus(201);

        expect($trade->fresh()->is_imported)->toBeTrue();
    });

    it('accepts reimporting a trade that is already marked as imported', function () {
        $trade = importableTrade(null, ['is_imported' => true]);

        $this->actingAs(makeAuthorizedImportUser())
            ->postJson(route('trades.import', ['trade' => $trade->id]))
            ->assertStatus(201);

        expect($trade->fresh()->is_imported)->toBeTrue();
        $this->assertDatabaseHas('keys', [
            'key_code' => 'AAAAA-BBBBB-CCCCC',
            'trade_id' => $trade->id,
        ]);
    });

    // ── Lote recusado ─────────────────────────────────────────────────────────

    it('refuses the batch when a filled line has no key code', function () {
        $trade = importableTrade([importLine(['key_code' => null])]);

        $this->actingAs(makeAuthorizedImportUser())
            ->postJson(route('trades.import', ['trade' => $trade->id]))
            ->assertStatus(422)
            ->assertJsonPath('count', 0);

        expect(DB::table('keys')->count())->toBe(0)
            ->and($trade->fresh()->is_imported)->toBeFalse();
    });

    it('refuses the batch when the trade has no TF2 quantity', function () {
        $trade = importableTrade(null, ['tf2_qty' => null]);

        $this->actingAs(makeAuthorizedImportUser())
            ->postJson(route('trades.import', ['trade' => $trade->id]))
            ->assertStatus(422);

        expect(DB::table('keys')->count())->toBe(0);
    });

    // ── Canal de compra ───────────────────────────────────────────────────────

    it('imports a direct bundle store purchase without a supplier', function () {
        $bundleId = DB::table('bundles')->insertGetId(['name' => 'Humble Choice September', 'created_at' => now(), 'updated_at' => now()]);
        $trade = importableTrade(null, [
            'supplier_id' => null,
            'purchase_channel' => 'bundle_store',
            'bundle_id' => $bundleId,
        ]);

        $this->actingAs(makeAuthorizedImportUser())
            ->postJson(route('trades.import', ['trade' => $trade->id]))
            ->assertStatus(201);

        $this->assertDatabaseHas('keys', [
            'key_code' => 'AAAAA-BBBBB-CCCCC',
            'trade_id' => $trade->id,
            'supplier_id' => null,
            'supplier_url' => 'Humble Choice September',
        ]);
    });

    it('refuses a direct bundle store purchase without its bundle', function () {
        $trade = importableTrade(null, ['supplier_id' => null, 'purchase_channel' => 'bundle_store']);

        $this->actingAs(makeAuthorizedImportUser())
            ->postJson(route('trades.import', ['trade' => $trade->id]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nenhuma key foi cadastrada — compra direta sem bundle');

        expect(DB::table('keys')->count())->toBe(0);
    });

    // ── Permissão ─────────────────────────────────────────────────────────────

    it('blocks an unauthorized visitor from importing', function () {
        $trade = importableTrade();

        $this->postJson(route('trades.import', ['trade' => $trade->id]))
            ->assertStatus(403);

        expect(DB::table('keys')->count())->toBe(0);
    });
});
