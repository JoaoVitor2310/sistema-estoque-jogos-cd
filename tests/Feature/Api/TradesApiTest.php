<?php

/*
|--------------------------------------------------------------------------
| POST /api/trades — HTTP
|--------------------------------------------------------------------------
|
| A borda da trade com oferta: autenticação, validação e as
| três respostas (201 criada, 200 `created: false`, 503 sem cotação). A
| regra em si está em CreateTradeWithOfferUseCaseTest.
|
*/

use App\Models\Trade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

const API_EXTERNAL_SECRET = 'test-price-researcher-token';

function seedTradesApiDeps(float $tf2Euro = 0.95): void
{
    Cache::flush();

    DB::table('fees')->insertOrIgnore([
        ['name' => 'gamivo_percent_low', 'preco' => 0.06, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_low', 'preco' => 0.25, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_percent_high', 'preco' => 0.08, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_high', 'preco' => 0.40, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('assets')->insert([
        'name' => 'TF2', 'price_euro' => $tf2Euro, 'price_dollar' => 0.0, 'price_brl' => 0.0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function tradesApiPayload(array $overrides = []): array
{
    return array_merge([
        'supplier_steam_id' => '76561198000000077',
        'games' => [['name' => 'Half-Life', 'market_price_euro' => 4.50, 'popularity' => 500, 'region' => 'EU']],
    ], $overrides);
}

beforeEach(function () {
    Config::set('services.external_secret', API_EXTERNAL_SECRET);
    seedTradesApiDeps();
});

describe('POST /api/trades — authentication', function () {

    it('refuses a request without a token', function () {
        $this->postJson('/api/trades', tradesApiPayload())->assertStatus(401);
    });

    it('refuses a wrong token', function () {
        $this->withToken('wrong')->postJson('/api/trades', tradesApiPayload())->assertStatus(401);
    });

    it('refuses everything when the secret is not configured', function () {
        Config::set('services.external_secret', null);

        $this->withToken('anything')->postJson('/api/trades', tradesApiPayload())->assertStatus(401);
    });
});

describe('POST /api/trades — validation', function () {

    it('requires the supplier and at least one game', function (array $payload, string $field) {
        $this->withToken(API_EXTERNAL_SECRET)->postJson('/api/trades', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field]);
    })->with([
        'no supplier' => [tradesApiPayload(['supplier_steam_id' => null]), 'supplier_steam_id'],
        'supplier that is not a SteamID64' => [tradesApiPayload(['supplier_steam_id' => 'carcadeals']), 'supplier_steam_id'],
        'no games' => [tradesApiPayload(['games' => []]), 'games'],
        'game without a price' => [tradesApiPayload(['games' => [['name' => 'Half-Life', 'popularity' => 500]]]), 'games.0.market_price_euro'],
        'unknown currency' => [tradesApiPayload(['currency' => 'brl']), 'currency'],
    ]);
});

describe('POST /api/trades — responses', function () {

    it('answers 201 with the uuid, the date and the effective currency', function () {
        $response = $this->withToken(API_EXTERNAL_SECRET)
            ->postJson('/api/trades', tradesApiPayload(['currency' => 'usd']))
            ->assertStatus(201)
            ->assertJsonStructure(['uuid', 'created_at', 'currency']);

        // Sem cotação em dólar a trade nasce em TF2, e a resposta diz isso
        expect($response->json('currency'))->toBe('tf2')
            // Mesma forma de data das leituras da API: ISO 8601 com o fuso do ambiente
            ->and($response->json('created_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/')
            ->and(Trade::where('uuid', $response->json('uuid'))->exists())->toBeTrue();
    });

    it('answers 200 created false, not an error, when there is nothing to offer', function () {
        $this->withToken(API_EXTERNAL_SECRET)
            ->postJson('/api/trades', tradesApiPayload(['games' => [['name' => 'Centavos', 'market_price_euro' => 0.05, 'popularity' => 500]]]))
            ->assertStatus(200)
            ->assertExactJson(['created' => false]);

        expect(Trade::count())->toBe(0);
    });

    it('answers 503 without the TF2 price in euro', function () {
        DB::table('assets')->update(['price_euro' => 0.0]);
        Cache::flush();

        $this->withToken(API_EXTERNAL_SECRET)->postJson('/api/trades', tradesApiPayload())
            ->assertStatus(503);

        expect(Trade::count())->toBe(0);
    });

    it('does not open a session or set cookies', function () {
        $response = $this->withToken(API_EXTERNAL_SECRET)->postJson('/api/trades', tradesApiPayload());

        expect($response->headers->getCookies())->toBe([]);
    });
});
