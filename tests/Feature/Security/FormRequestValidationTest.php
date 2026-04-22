<?php

/*
|--------------------------------------------------------------------------
| FormRequest validation — characterization tests (6.5 + 6.6)
|--------------------------------------------------------------------------
|
| 6.5 — precoCliente e qtdTF2 devem ser > 0.
| 6.6 — tipo_reclamacao_id, tipo_formato_id e campos leilão/plataforma
|        usam exists:table,id — IDs inexistentes são rejeitados.
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
    DB::table('tipo_reclamacao')->insertOrIgnore(['id' => 1, 'name' => 'Nenhuma']);
    DB::table('tipo_formato')->insertOrIgnore(['id' => 1, 'name' => 'Key']);
    DB::table('tipo_leilao')->insertOrIgnore(['id' => 1, 'name' => 'Fixo']);
    DB::table('plataforma')->insertOrIgnore(['id' => 1, 'name' => 'Gamivo']);
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
                'notaMetacritic' => 0,
                'tipo_reclamacao_id' => 1,
                'tipo_formato_id' => 1,
                'id_leilao_g2a' => 1,
                'id_leilao_gamivo' => 1,
                'id_leilao_kinguin' => 1,
                'id_plataforma' => 1,
                'vendido' => false,
                'leiloes' => 1,
                'quantidade' => 1,
                'devolucoes' => false,
                'isSteam' => false,
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

    // ── 6.6: FKs dinâmicas ───────────────────────────────────────────────────

    describe('tipo_reclamacao_id (6.6)', function () {

        it('rejects an ID that does not exist in tipo_reclamacao', function () {
            $user = requestAuthorizedUser();

            $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['tipo_reclamacao_id' => 9999]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['games.0.tipo_reclamacao_id']);
        });

        it('accepts ID 1 which exists in tipo_reclamacao', function () {
            $user = requestAuthorizedUser();

            $response = $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['tipo_reclamacao_id' => 1]));

            expect($response->status())->not->toBe(422);
        });

        it('accepts a newly inserted tipo_reclamacao without code change', function () {
            DB::table('tipo_reclamacao')->insertOrIgnore(['id' => 5, 'name' => 'Novo tipo']);
            $user = requestAuthorizedUser();

            $response = $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['tipo_reclamacao_id' => 5]));

            // O exists: consulta o banco — id 5 acabou de ser inserido, não deve ser rejeitado
            expect($response->status())->not->toBe(422);
        });
    });

    describe('tipo_formato_id (6.6)', function () {

        it('rejects an ID that does not exist in tipo_formato', function () {
            $user = requestAuthorizedUser();

            $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['tipo_formato_id' => 9999]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['games.0.tipo_formato_id']);
        });
    });

    describe('id_plataforma (6.6)', function () {

        it('rejects an ID that does not exist in plataforma', function () {
            $user = requestAuthorizedUser();

            $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['id_plataforma' => 9999]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['games.0.id_plataforma']);
        });
    });

    describe('id_leilao_gamivo (6.6)', function () {

        it('rejects an ID that does not exist in tipo_leilao', function () {
            $user = requestAuthorizedUser();

            $this->actingAs($user)
                ->postJson('/venda-chave-troca', gamePayload(['id_leilao_gamivo' => 9999]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['games.0.id_leilao_gamivo']);
        });
    });
});
