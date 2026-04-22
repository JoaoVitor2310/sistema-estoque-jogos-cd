<?php

/*
|--------------------------------------------------------------------------
| FormRequest validation — characterization tests (6.5)
|--------------------------------------------------------------------------
|
| 6.5 — precoCliente e qtdTF2 devem ser > 0.
|
| A rota POST /venda-chave-troca usa StoreGameRequestArray, que espera
| os dados no formato { games: [...] }. Os erros de validação são retornados
| com chaves no formato "games.0.fieldName".
|
*/

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

    describe('precoCliente (6.5)', function () {

        it('rejects precoCliente = 0', function () {
            $user = requestAuthorizedUser();

            $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['precoCliente' => 0]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['games.0.precoCliente']);
        });

        it('rejects negative precoCliente', function () {
            $user = requestAuthorizedUser();

            $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['precoCliente' => -1.50]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['games.0.precoCliente']);
        });

        it('accepts precoCliente > 0', function () {
            $user = requestAuthorizedUser();

            $response = $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['precoCliente' => 5.00]));

            expect($response->status())->not->toBe(422);
        });
    });

    describe('qtdTF2 (6.5)', function () {

        it('rejects qtdTF2 = 0', function () {
            $user = requestAuthorizedUser();

            $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['qtdTF2' => 0]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['games.0.qtdTF2']);
        });

        it('rejects negative qtdTF2', function () {
            $user = requestAuthorizedUser();

            $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['qtdTF2' => -2.0]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['games.0.qtdTF2']);
        });

        it('accepts qtdTF2 > 0', function () {
            $user = requestAuthorizedUser();

            $response = $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['qtdTF2' => 2.0]));

            expect($response->status())->not->toBe(422);
        });
    });

    describe('valorVendido (6.5)', function () {

        it('rejects valorVendido = 0 when provided', function () {
            $user = requestAuthorizedUser();

            $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['valorVendido' => 0]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['games.0.valorVendido']);
        });

        it('accepts null valorVendido (key ainda não vendida)', function () {
            $user = requestAuthorizedUser();

            $response = $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['valorVendido' => null]));

            expect($response->status())->not->toBe(422);
        });
    });
});
