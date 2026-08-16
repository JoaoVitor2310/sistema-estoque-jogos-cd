<?php

/*
|--------------------------------------------------------------------------
| Import validation — characterization tests
|--------------------------------------------------------------------------
|
| market_price e tf2_quantity devem ser > 0.
|
| A rota POST /trades/{trade}/import é o único ponto de entrada de keys no
| sistema, e **não recebe corpo**: o lote sai das linhas gravadas da trade.
| A garantia que antes vivia no `ImportTradeKeysRequest` passou a ser regra de
| Domain (`App\Domain\Trades\ImportReadinessPolicy`) — estes testes fixam que
| ela continua valendo na fronteira HTTP, agora sobre o dado persistido.
|
| Os enums (claim_type, key_format, sell_platform) não entram aqui: a linha da
| trade não os carrega, então assumem o valor de KeyDefaults. A validação por
| enum deles vive no StoreGameRequest (edição inline de key).
|
*/

use App\Models\AuthorizedUsers;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\TradeFactory;

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedValidationFks(): void
{
    DB::table('fees')->insertOrIgnore([
        ['name' => 'gamivo_percent_low', 'preco' => 0.060, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_low',       'preco' => 0.250, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_percent_high', 'preco' => 0.080, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_high',       'preco' => 0.400, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('assets')->insertOrIgnore(['id' => 1, 'name' => 'TF2', 'price_euro' => 2.0, 'price_dollar' => 2.2, 'price_brl' => 10.0, 'created_at' => now(), 'updated_at' => now()]);
}

function requestAuthorizedUser(): User
{
    $user = User::factory()->create();
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    return $user;
}

/**
 * Trade pronta para importar; os overrides quebram só o campo sob teste.
 *
 * @param  array<string, mixed>  $lineOverrides  colunas da linha
 * @param  array<string, mixed>  $tradeOverrides  colunas da trade
 */
function tradeReadyToImport(array $lineOverrides = [], array $tradeOverrides = []): Trade
{
    $supplierId = DB::table('suppliers')->insertGetId([
        'url' => 'https://steamcommunity.com/id/seller',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return TradeFactory::withLines(
        [array_merge([
            'game_name' => 'Test Game',
            'key_code' => 'AAAAA-11111-BBBBB',
            'market_price' => 5.00,
            'region' => null,
        ], $lineOverrides)],
        array_merge([
            'supplier_id' => $supplierId,
            'tf2_qty' => 2.0,
            'date' => now()->toDateString(),
        ], $tradeOverrides),
    );
}

function importResponse(Trade $trade)
{
    return test()->actingAs(requestAuthorizedUser())
        ->postJson(route('trades.import', ['trade' => $trade->id]));
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('Import readiness at the HTTP boundary', function () {

    beforeEach(function () {
        seedValidationFks();
    });

    // ── 6.5: preços > 0 ──────────────────────────────────────────────────────

    describe('market_price (6.5)', function () {

        it('rejects market_price = 0', function () {
            importResponse(tradeReadyToImport(['market_price' => 0]))->assertStatus(422);

            expect(DB::table('keys')->count())->toBe(0);
        });

        it('rejects negative market_price', function () {
            importResponse(tradeReadyToImport(['market_price' => -1.50]))->assertStatus(422);

            expect(DB::table('keys')->count())->toBe(0);
        });

        it('accepts market_price > 0', function () {
            expect(importResponse(tradeReadyToImport(['market_price' => 5.00]))->status())->not->toBe(422);
        });
    });

    describe('tf2_quantity (6.5)', function () {

        it('rejects tf2_quantity = 0', function () {
            importResponse(tradeReadyToImport([], ['tf2_qty' => 0]))->assertStatus(422);

            expect(DB::table('keys')->count())->toBe(0);
        });

        it('rejects negative tf2_quantity', function () {
            importResponse(tradeReadyToImport([], ['tf2_qty' => -2.0]))->assertStatus(422);

            expect(DB::table('keys')->count())->toBe(0);
        });

        it('accepts tf2_quantity > 0', function () {
            expect(importResponse(tradeReadyToImport([], ['tf2_qty' => 2.0]))->status())->not->toBe(422);
        });
    });
});
