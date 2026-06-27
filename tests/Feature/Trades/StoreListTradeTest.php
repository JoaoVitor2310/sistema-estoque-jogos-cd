<?php

/*
|--------------------------------------------------------------------------
| StoreListTradeTest — POST /trades/from-price-researcher
|--------------------------------------------------------------------------
|
| Casos testados:
|
|   Autenticação:
|     1. Sem Bearer token    → 401
|     2. Token errado        → 401
|
|   Validação:
|     3. Body vazio           → 422
|     4. games ausente        → 422
|     5. games vazio          → 422
|     6. game sem name        → 422
|     7. game sem price_euro  → 422
|     8. game sem popularity  → 422
|
|   Criação:
|     9.  Cria trade com games convertidos → 201
|     10. supplier_steam_id opcional — supplier criado quando enviado
|     11. supplier_steam_id ausente — trade sem supplier_id
|     12. list_code armazenado quando enviado
|     13. list_code null quando ausente
|
*/

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

const LIST_TRADE_SECRET = 'test-list-trade-secret';

function validListPayload(array $overrides = []): array
{
    return array_merge([
        'games' => [
            ['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => 'EU'],
        ],
    ], $overrides);
}

// ── Autenticação ──────────────────────────────────────────────────────────────

describe('POST /trades/from-price-researcher — authentication', function () {

    beforeEach(fn () => Config::set('services.external_secret', LIST_TRADE_SECRET));

    it('returns 401 with no bearer token', function () {
        $this->postJson('/trades/from-price-researcher', validListPayload())
            ->assertStatus(401);
    });

    it('returns 401 with wrong bearer token', function () {
        $this->withToken('wrong-secret')
            ->postJson('/trades/from-price-researcher', validListPayload())
            ->assertStatus(401);
    });
});

// ── Validação ─────────────────────────────────────────────────────────────────

describe('POST /trades/from-price-researcher — validation', function () {

    beforeEach(fn () => Config::set('services.external_secret', LIST_TRADE_SECRET));

    it('returns 422 when body is empty', function () {
        $this->withToken(LIST_TRADE_SECRET)
            ->postJson('/trades/from-price-researcher', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['games']);
    });

    it('returns 422 when games is missing', function () {
        $this->withToken(LIST_TRADE_SECRET)
            ->postJson('/trades/from-price-researcher', ['list_code' => 'G0eXM'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['games']);
    });

    it('returns 422 when games is empty', function () {
        $this->withToken(LIST_TRADE_SECRET)
            ->postJson('/trades/from-price-researcher', validListPayload(['games' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['games']);
    });

    it('returns 422 when a game is missing name', function () {
        $this->withToken(LIST_TRADE_SECRET)
            ->postJson('/trades/from-price-researcher', validListPayload([
                'games' => [['price_euro' => 4.50, 'popularity' => 500]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['games.0.name']);
    });

    it('returns 422 when a game is missing price_euro', function () {
        $this->withToken(LIST_TRADE_SECRET)
            ->postJson('/trades/from-price-researcher', validListPayload([
                'games' => [['name' => 'Half-Life', 'popularity' => 500]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['games.0.price_euro']);
    });

    it('returns 422 when a game is missing popularity', function () {
        $this->withToken(LIST_TRADE_SECRET)
            ->postJson('/trades/from-price-researcher', validListPayload([
                'games' => [['name' => 'Half-Life', 'price_euro' => 4.50]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['games.0.popularity']);
    });
});

// ── Criação ───────────────────────────────────────────────────────────────────

describe('POST /trades/from-price-researcher — trade creation', function () {

    beforeEach(fn () => Config::set('services.external_secret', LIST_TRADE_SECRET));

    it('creates trade and returns 201 with id and created_at', function () {
        $response = $this->withToken(LIST_TRADE_SECRET)
            ->postJson('/trades/from-price-researcher', validListPayload())
            ->assertStatus(201)
            ->assertJsonStructure(['id', 'created_at']);

        $this->assertDatabaseCount('trades', 1);
        expect($response->json('id'))->toBeInt();
    });

    it('creates supplier and links trade when supplier_steam_id is provided', function () {
        $this->withToken(LIST_TRADE_SECRET)
            ->postJson('/trades/from-price-researcher', validListPayload([
                'supplier_steam_id' => '76561198000000001',
            ]))
            ->assertStatus(201);

        $supplier = DB::table('suppliers')->where('steam_id', '76561198000000001')->first();
        expect($supplier)->not->toBeNull();

        $trade = DB::table('trades')->latest()->first();
        expect($trade->supplier_id)->toBe($supplier->id);
    });

    it('creates trade without supplier_id when supplier_steam_id is omitted', function () {
        $this->withToken(LIST_TRADE_SECRET)
            ->postJson('/trades/from-price-researcher', validListPayload())
            ->assertStatus(201);

        $trade = DB::table('trades')->latest()->first();
        expect($trade->supplier_id)->toBeNull();
    });

    it('stores list_code in trade when provided', function () {
        $this->withToken(LIST_TRADE_SECRET)
            ->postJson('/trades/from-price-researcher', validListPayload(['list_code' => 'G0eXM']))
            ->assertStatus(201);

        expect(DB::table('trades')->where('list_code', 'G0eXM')->exists())->toBeTrue();
    });

    it('stores null list_code when list_code is omitted', function () {
        $this->withToken(LIST_TRADE_SECRET)
            ->postJson('/trades/from-price-researcher', validListPayload())
            ->assertStatus(201);

        expect(DB::table('trades')->whereNull('list_code')->exists())->toBeTrue();
    });
});
