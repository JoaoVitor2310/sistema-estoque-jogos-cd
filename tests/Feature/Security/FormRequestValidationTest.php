<?php

/*
|--------------------------------------------------------------------------
| FormRequest validation — characterization tests
|--------------------------------------------------------------------------
|
| market_price e tf2_quantity devem ser > 0.
|
| A rota POST /trades/{trade}/import usa ImportTradeKeysRequest — o único
| ponto de entrada de keys no sistema. Espera os dados no formato
| { games: [...] }; os erros de validação são retornados com chaves no
| formato "games.0.fieldName".
|
| Os enums (claim_type, key_format, sell_platform) não são validados aqui: o
| TradeCalculator não os envia, então assumem o valor de KeyDefaults. A
| validação por enum deles vive no StoreGameRequest (edição inline de key).
|
*/

use App\Models\AuthorizedUsers;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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

/** Rota de importação de uma trade nova — único caminho de entrada de keys. */
function tradeImportRoute(): string
{
    return route('trades.import', ['trade' => Trade::create(['games' => []])->id]);
}

/** Retorna um payload válido para ImportTradeKeysRequest (games: [...]) */
function gamePayload(array $overrides = []): array
{
    return [
        'games' => [
            array_merge([
                'game_name' => 'Test Game',
                'key_code' => 'AAAAA-11111-BBBBB',
                'supplier_url' => 'https://steamcommunity.com/id/seller',
                'tf2_quantity' => 2.0,
                'market_price' => 5.00,
                'region' => null,
                'acquired_at' => now()->toDateString(),
                'gamivo_id' => null,
                'steam_id' => null,
                'claim_type' => 'Nenhuma',
                'key_format' => 'RK',
                'sell_platform' => 'Gamivo',
                'color' => null,
            ], $overrides),
        ],
    ];
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('ImportTradeKeysRequest validation', function () {

    beforeEach(function () {
        seedValidationFks();
    });

    // ── 6.5: preços > 0 ──────────────────────────────────────────────────────

    describe('market_price (6.5)', function () {

        it('rejects market_price = 0', function () {
            $user = requestAuthorizedUser();

            $this->actingAs($user)
                ->postJson(tradeImportRoute(), gamePayload(['market_price' => 0]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['games.0.market_price']);
        });

        it('rejects negative market_price', function () {
            $user = requestAuthorizedUser();

            $this->actingAs($user)
                ->postJson(tradeImportRoute(), gamePayload(['market_price' => -1.50]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['games.0.market_price']);
        });

        it('accepts market_price > 0', function () {
            $user = requestAuthorizedUser();

            $response = $this->actingAs($user)
                ->postJson(tradeImportRoute(), gamePayload(['market_price' => 5.00]));

            expect($response->status())->not->toBe(422);
        });
    });

    describe('tf2_quantity (6.5)', function () {

        it('rejects tf2_quantity = 0', function () {
            $user = requestAuthorizedUser();

            $this->actingAs($user)
                ->postJson(tradeImportRoute(), gamePayload(['tf2_quantity' => 0]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['games.0.tf2_quantity']);
        });

        it('rejects negative tf2_quantity', function () {
            $user = requestAuthorizedUser();

            $this->actingAs($user)
                ->postJson(tradeImportRoute(), gamePayload(['tf2_quantity' => -2.0]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['games.0.tf2_quantity']);
        });

        it('accepts tf2_quantity > 0', function () {
            $user = requestAuthorizedUser();

            $response = $this->actingAs($user)
                ->postJson(tradeImportRoute(), gamePayload(['tf2_quantity' => 2.0]));

            expect($response->status())->not->toBe(422);
        });
    });
});
