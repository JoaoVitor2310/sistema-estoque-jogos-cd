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
|     3. Body vazio                    → 422
|     4. supplier_steam_id ausente     → 422
|     5. games ausente                 → 422
|     6. games vazio                   → 422
|
|   Upsert do supplier:
|     8.  Novo supplier é criado com URL derivada do steam_id
|     9.  Supplier existente (mesmo steam_id) não é duplicado
|
|   Rentabilidade:
|     10. Todos rentáveis → profitable preenchido, is_added reflete o banco
|     11. Nenhum rentável → profitable vazio
|
|   is_added:
|     12. Novo supplier → is_added = false
|     13. Supplier existente com is_added = true → retorna true
|
|   Trade:
|     14. Cria trade com supplier_id quando há jogos rentáveis
|     15. Não cria trade quando nenhum jogo é rentável
|     16. Games da trade contêm os campos corretos
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

const SUPPLIER_STEAM_ID = '76561198000000001';
const SUPPLIER_URL = 'https://steamcommunity.com/profiles/76561198000000001';

function validPayload(array $overrides = []): array
{
    return array_merge([
        'supplier_steam_id' => SUPPLIER_STEAM_ID,
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
            ->assertJsonValidationErrors(['supplier_steam_id', 'games']);
    });

    it('returns 422 when supplier_steam_id is missing', function () {
        $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', ['games' => [validPayload()['games'][0]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['supplier_steam_id']);
    });

    it('returns 422 when games is missing', function () {
        $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', ['supplier_steam_id' => SUPPLIER_STEAM_ID])
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

    it('creates a new supplier with URL derived from steam_id', function () {
        $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', validPayload())
            ->assertStatus(200);

        $this->assertDatabaseHas('suppliers', [
            'steam_id' => SUPPLIER_STEAM_ID,
            'url' => SUPPLIER_URL,
            'is_added' => false,
        ]);
    });

    it('does not duplicate supplier when steam_id already exists', function () {
        DB::table('suppliers')->insert([
            'steam_id' => SUPPLIER_STEAM_ID,
            'url' => SUPPLIER_URL,
            'is_added' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', validPayload())
            ->assertStatus(200);

        $this->assertDatabaseCount('suppliers', 1);
    });
});

// ── Trade ─────────────────────────────────────────────────────────────────────

describe('POST /suppliers/prospect — trade creation', function () {

    beforeEach(function () {
        Config::set('services.external_secret', PROSPECT_SECRET);
        seedProspectDeps();
    });

    it('creates a trade associated with the supplier when there are profitable games', function () {
        $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', validPayload())
            ->assertStatus(200);

        $supplierId = DB::table('suppliers')->where('steam_id', '76561198000000001')->value('id');

        $this->assertDatabaseHas('trades', ['supplier_id' => $supplierId]);
    });

    it('does not create a trade when no games are profitable', function () {
        $payload = validPayload(['games' => [
            ['name' => 'Junk Game', 'price_euro' => 0.05, 'popularity' => 1, 'region' => null],
        ]]);

        $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', $payload)
            ->assertStatus(200);

        $this->assertDatabaseCount('trades', 0);
    });

    it('stores correct fields in trade games', function () {
        $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', validPayload())
            ->assertStatus(200);

        $trade = DB::table('trades')->latest()->first();
        $game = json_decode($trade->games, true)[0];

        expect($game['name'])->toBe('Half-Life')
            ->and($game['marketPriceRaw'])->toBe('4.50')
            ->and($game['popularity'])->toBe('500')
            ->and($game['keyCode'])->toBeNull();

        expect($trade->date)->not->toBeNull();
    });
});

// ── is_added ──────────────────────────────────────────────────────────────────

describe('POST /suppliers/prospect — is_added', function () {

    beforeEach(function () {
        Config::set('services.external_secret', PROSPECT_SECRET);
        seedProspectDeps();
    });

    it('returns is_added false for a new supplier', function () {
        $response = $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', validPayload())
            ->assertStatus(200);

        expect($response->json('is_added'))->toBeFalse();
    });

    it('returns is_added true when supplier already exists with is_added = true', function () {
        DB::table('suppliers')->insert([
            'steam_id' => SUPPLIER_STEAM_ID,
            'url' => SUPPLIER_URL,
            'is_added' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', validPayload())
            ->assertStatus(200);

        expect($response->json('is_added'))->toBeTrue();
    });
});

// ── Rentabilidade ─────────────────────────────────────────────────────────────

describe('POST /suppliers/prospect — profitability', function () {

    beforeEach(function () {
        Config::set('services.external_secret', PROSPECT_SECRET);
        seedProspectDeps();
    });

    it('returns profitable games when there are profitable games', function () {
        $response = $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', validPayload())
            ->assertStatus(200)
            ->assertJsonStructure(['profitable', 'is_added', 'last_commented_at', 'games_changed', 'should_comment']);

        expect($response->json('profitable'))->not->toBeEmpty();
    });

    it('returns empty profitable when no games are profitable', function () {
        $payload = validPayload(['games' => [
            ['name' => 'Junk Game', 'price_euro' => 0.05, 'popularity' => 1, 'region' => null],
        ]]);

        $response = $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', $payload)
            ->assertStatus(200)
            ->assertJsonStructure(['profitable', 'is_added', 'last_commented_at', 'games_changed', 'should_comment']);

        expect($response->json('profitable'))->toBeEmpty();
    });
});

// ── list_code ─────────────────────────────────────────────────────────────────

describe('POST /suppliers/prospect — list_code', function () {

    beforeEach(function () {
        Config::set('services.external_secret', PROSPECT_SECRET);
        seedProspectDeps();
    });

    it('stores list_code in the created trade when provided', function () {
        $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', validPayload(['list_code' => 'G0eXM']))
            ->assertStatus(200);

        expect(DB::table('trades')->where('list_code', 'G0eXM')->exists())->toBeTrue();
    });

    it('list_code is optional — trade is created with null list_code', function () {
        $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', validPayload())
            ->assertStatus(200);

        expect(DB::table('trades')->whereNull('list_code')->exists())->toBeTrue();
    });

    it('returns last_commented_at and games_changed in the response', function () {
        $response = $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', validPayload(['list_code' => 'G0eXM']))
            ->assertStatus(200)
            ->assertJsonStructure(['profitable', 'is_added', 'last_commented_at', 'games_changed', 'should_comment']);

        expect($response->json('last_commented_at'))->toBeNull()
            ->and($response->json('games_changed'))->toBeFalse();
    });
});
