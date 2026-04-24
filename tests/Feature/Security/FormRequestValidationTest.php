<?php

/*
|--------------------------------------------------------------------------
| FormRequest validation — characterization tests (6.5 e 6.6)
|--------------------------------------------------------------------------
|
| 6.5 — market_price e tf2_quantity devem ser > 0.
| 6.6 — claim_type, key_format e sell_platform devem ser valores válidos
|        dos respectivos Enums do Domain.
|
| A rota POST /venda-chave-troca usa StoreGameRequestArray, que espera
| os dados no formato { games: [...] }. Os erros de validação são retornados
| com chaves no formato "games.0.fieldName".
|
*/

use App\Domain\Enums\ClaimType;
use App\Domain\Enums\KeyFormat;
use App\Domain\Enums\SellPlatform;
use App\Models\AuthorizedUsers;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedValidationFks(): void
{
    DB::table('taxas')->insertOrIgnore([
        ['name' => 'gamivoPercentualMenor', 'preco' => 0.072, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivoFixoMenor',       'preco' => 0.110, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivoPercentualMaior', 'preco' => 0.102, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivoFixoMaior',       'preco' => 0.550, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('recursos')->insertOrIgnore(['id' => 1, 'name' => 'TF2', 'preco_euro' => 2.0, 'preco_dolar' => 2.2, 'preco_real' => 10.0, 'created_at' => now(), 'updated_at' => now()]);
}

function requestAuthorizedUser(): User
{
    $user = User::factory()->create();
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    return $user;
}

/** Retorna um payload válido para StoreGameRequestArray (games: [...]) */
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
                'steamId' => null,
                'precoJogo' => null,
                'claim_type' => 'Nenhuma',
                'key_format' => 'RK',
                'sell_platform' => 'Gamivo',
                'color' => null,
            ], $overrides),
        ],
    ];
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('StoreGameRequest validation', function () {

    beforeEach(function () {
        seedValidationFks();
    });

    // ── 6.5: preços > 0 ──────────────────────────────────────────────────────

    describe('market_price (6.5)', function () {

        it('rejects market_price = 0', function () {
            $user = requestAuthorizedUser();

            $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['market_price' => 0]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['games.0.market_price']);
        });

        it('rejects negative market_price', function () {
            $user = requestAuthorizedUser();

            $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['market_price' => -1.50]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['games.0.market_price']);
        });

        it('accepts market_price > 0', function () {
            $user = requestAuthorizedUser();

            $response = $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['market_price' => 5.00]));

            expect($response->status())->not->toBe(422);
        });
    });

    describe('tf2_quantity (6.5)', function () {

        it('rejects tf2_quantity = 0', function () {
            $user = requestAuthorizedUser();

            $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['tf2_quantity' => 0]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['games.0.tf2_quantity']);
        });

        it('rejects negative tf2_quantity', function () {
            $user = requestAuthorizedUser();

            $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['tf2_quantity' => -2.0]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['games.0.tf2_quantity']);
        });

        it('accepts tf2_quantity > 0', function () {
            $user = requestAuthorizedUser();

            $response = $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['tf2_quantity' => 2.0]));

            expect($response->status())->not->toBe(422);
        });
    });

    describe('sold_price (6.5)', function () {

        it('rejects sold_price = 0 when provided', function () {
            $user = requestAuthorizedUser();

            $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['sold_price' => 0]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['games.0.sold_price']);
        });

        it('accepts null sold_price (key ainda não vendida)', function () {
            $user = requestAuthorizedUser();

            $response = $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['sold_price' => null]));

            expect($response->status())->not->toBe(422);
        });
    });

    // ── 6.6: enum fields ─────────────────────────────────────────────────────

    describe('claim_type ', function () {

        it('rejects an arbitrary string', function () {
            $user = requestAuthorizedUser();

            $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['claim_type' => 'INVALIDO']))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['games.0.claim_type']);
        });

        it('accepts null claim_type', function () {
            $user = requestAuthorizedUser();

            $response = $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['claim_type' => null]));

            expect($response->status())->not->toBe(422);
        });

        it('accepts every valid ClaimType value', function () {
            $user = requestAuthorizedUser();

            foreach (ClaimType::cases() as $case) {
                $response = $this->actingAs($user)
                    ->postJson('/venda-chave-troca', gamePayload(['claim_type' => $case->value]));

                expect($response->status())->not->toBe(422, "claim_type '{$case->value}' deveria ser aceito");
            }
        });
    });

    describe('key_format ', function () {

        it('rejects an arbitrary string', function () {
            $user = requestAuthorizedUser();

            $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['key_format' => 'INVALIDO']))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['games.0.key_format']);
        });

        it('accepts null key_format', function () {
            $user = requestAuthorizedUser();

            $response = $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['key_format' => null]));

            expect($response->status())->not->toBe(422);
        });

        it('accepts every valid KeyFormat value', function () {
            $user = requestAuthorizedUser();

            foreach (KeyFormat::cases() as $case) {
                $response = $this->actingAs($user)
                    ->postJson('/venda-chave-troca', gamePayload(['key_format' => $case->value]));

                expect($response->status())->not->toBe(422, "key_format '{$case->value}' deveria ser aceito");
            }
        });
    });

    describe('sell_platform ', function () {

        it('rejects an arbitrary string', function () {
            $user = requestAuthorizedUser();

            $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['sell_platform' => 'INVALIDO']))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['games.0.sell_platform']);
        });

        it('accepts null sell_platform', function () {
            $user = requestAuthorizedUser();

            $response = $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['sell_platform' => null]));

            expect($response->status())->not->toBe(422);
        });

        it('accepts every valid SellPlatform value', function () {
            $user = requestAuthorizedUser();

            foreach (SellPlatform::cases() as $case) {
                $response = $this->actingAs($user)
                    ->postJson('/venda-chave-troca', gamePayload(['sell_platform' => $case->value]));

                expect($response->status())->not->toBe(422, "sell_platform '{$case->value}' deveria ser aceito");
            }
        });
    });
});
