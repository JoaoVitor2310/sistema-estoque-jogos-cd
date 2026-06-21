<?php

/*
|--------------------------------------------------------------------------
| SupplierProspectTest — POST /suppliers/prospect
|--------------------------------------------------------------------------
|
| Casos testados:
|
|   Autenticação:
|     1. Sem Bearer token          → 401
|     2. Token errado              → 401
|
|   Validação:
|     3. Body vazio                → 422
|     4. supplier ausente          → 422
|     5. supplier.steam_id ausente → 422
|     6. supplier.url inválida     → 422
|     7. games ausente             → 422
|     8. games vazio               → 422
|
|   Upsert do supplier:
|     9.  Novo supplier é criado no banco
|     10. Supplier existente (mesmo steam_id) é atualizado
|
|   Rentabilidade:
|     11. Todos rentáveis → profitable preenchido, is_tradable = true
|     12. Nenhum rentável → profitable vazio, is_tradable = false
|
*/

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

const PROSPECT_SECRET = 'test-prospect-secret';

function seedProspectDeps(float $tf2Price = 0.95): void
{
    Cache::flush();

    DB::table('fees')->insertOrIgnore([
        ['name' => 'gamivo_percent_low', 'preco' => 0.06, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_low', 'preco' => 0.25, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_percent_high', 'preco' => 0.08, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_high', 'preco' => 0.40, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('assets')->insertOrIgnore([
        'name' => 'TF2',
        'price_euro' => $tf2Price,
        'price_dollar' => 0.00,
        'price_brl' => 0.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function validPayload(array $overrides = []): array
{
    return array_merge([
        'supplier' => [
            'steam_id' => '76561198000000001',
            'url' => 'https://steamcommunity.com/id/exemplo',
        ],
        'games' => [
            ['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => null],
        ],
    ], $overrides);
}

// ── Autenticação ──────────────────────────────────────────────────────────────

describe('POST /suppliers/prospect — authentication', function () {

    beforeEach(fn () => Config::set('services.external_secret', PROSPECT_SECRET));

    it('returns 401 with no bearer token', function () {
        $this->postJson('/suppliers/prospect', validPayload())
            ->assertStatus(401);
    });

    it('returns 401 with wrong bearer token', function () {
        $this->withToken('wrong-secret')
            ->postJson('/suppliers/prospect', validPayload())
            ->assertStatus(401);
    });
});

// ── Validação ─────────────────────────────────────────────────────────────────

describe('POST /suppliers/prospect — validation', function () {

    beforeEach(fn () => Config::set('services.external_secret', PROSPECT_SECRET));

    it('returns 422 when body is empty', function () {
        $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['supplier', 'games']);
    });

    it('returns 422 when supplier is missing', function () {
        $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', ['games' => [validPayload()['games'][0]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['supplier']);
    });

    it('returns 422 when supplier.steam_id is missing', function () {
        $payload = validPayload();
        unset($payload['supplier']['steam_id']);

        $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['supplier.steam_id']);
    });

    it('returns 422 when supplier.url is not a valid URL', function () {
        $payload = validPayload();
        $payload['supplier']['url'] = 'not-a-url';

        $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['supplier.url']);
    });

    it('returns 422 when games is missing', function () {
        $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', ['supplier' => validPayload()['supplier']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['games']);
    });

    it('returns 422 when games is empty', function () {
        $payload = validPayload(['games' => []]);

        $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['games']);
    });
});

// ── Upsert do supplier ────────────────────────────────────────────────────────

describe('POST /suppliers/prospect — supplier upsert', function () {

    beforeEach(function () {
        Config::set('services.external_secret', PROSPECT_SECRET);
        seedProspectDeps();
    });

    it('creates a new supplier when steam_id does not exist', function () {
        $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', validPayload())
            ->assertStatus(200);

        $this->assertDatabaseHas('suppliers', [
            'steam_id' => '76561198000000001',
            'url' => 'https://steamcommunity.com/id/exemplo',
            'is_added' => false,
        ]);
    });

    it('updates existing supplier when steam_id already exists', function () {
        DB::table('suppliers')->insert([
            'steam_id' => '76561198000000001',
            'url' => 'https://steamcommunity.com/id/antigo',
            'is_added' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = validPayload();
        $payload['supplier']['url'] = 'https://steamcommunity.com/id/novo';

        $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', $payload)
            ->assertStatus(200);

        $this->assertDatabaseHas('suppliers', [
            'steam_id' => '76561198000000001',
            'url' => 'https://steamcommunity.com/id/novo',
        ]);

        $this->assertDatabaseCount('suppliers', 1);
    });
});

// ── Rentabilidade ─────────────────────────────────────────────────────────────

describe('POST /suppliers/prospect — profitability', function () {

    beforeEach(function () {
        Config::set('services.external_secret', PROSPECT_SECRET);
        seedProspectDeps();
    });

    it('returns profitable games and is_tradable true when there are profitable games', function () {
        $response = $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', validPayload())
            ->assertStatus(200)
            ->assertJsonStructure(['profitable', 'is_tradable']);

        expect($response->json('profitable'))->not->toBeEmpty();
        expect($response->json('is_tradable'))->toBeTrue();
    });

    it('returns empty profitable and is_tradable false when no games are profitable', function () {
        $payload = validPayload(['games' => [
            ['name' => 'Junk Game', 'price_euro' => 0.05, 'popularity' => 1, 'region' => null],
        ]]);

        $response = $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', $payload)
            ->assertStatus(200);

        expect($response->json('profitable'))->toBeEmpty();
        expect($response->json('is_tradable'))->toBeFalse();
    });
});
