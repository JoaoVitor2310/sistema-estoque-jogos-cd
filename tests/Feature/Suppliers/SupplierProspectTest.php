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
|     11b. total_tf2_price = soma dos tf2_price dos jogos rentáveis
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
|   gamivo_id:
|     17. gamivo_id enviado é propagado para profitable e para os games da trade
|     18. gamivo_id ausente não quebra o request e é armazenado como null
|
*/

use App\Models\Trade;
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
            ['name' => 'Half-Life', 'market_price_euro' => 4.50, 'popularity' => 500, 'region' => null],
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
            ['name' => 'Junk Game', 'market_price_euro' => 0.05, 'popularity' => 1, 'region' => null],
        ]]);

        $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', $payload)
            ->assertStatus(200);

        $this->assertDatabaseCount('trades', 0);
    });

    it('stores correct fields on the created trade lines', function () {
        $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', validPayload())
            ->assertStatus(200);

        $trade = Trade::latest('id')->firstOrFail();
        $line = $trade->lines->first();

        expect($line->game_name)->toBe('Half-Life')
            ->and($line->market_price)->toBe('4.50')
            ->and($line->popularity)->toBe(500)
            ->and($line->key_code)->toBeNull();

        expect($trade->date)->not->toBeNull();
    });
});

// ── gamivo_id ─────────────────────────────────────────────────────────────────

describe('POST /suppliers/prospect — gamivo_id', function () {

    beforeEach(function () {
        Config::set('services.external_secret', PROSPECT_SECRET);
        seedProspectDeps();
    });

    it('propagates gamivo_id to profitable and to the created trade line', function () {
        $payload = validPayload(['games' => [
            ['name' => 'Half-Life', 'market_price_euro' => 4.50, 'popularity' => 500, 'region' => null, 'gamivo_id' => '144601'],
        ]]);

        $response = $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', $payload)
            ->assertStatus(200);

        expect($response->json('profitable.0.gamivo_id'))->toBe('144601');

        expect(Trade::latest('id')->firstOrFail()->lines->first()->gamivo_id)->toBe('144601');
    });

    it('accepts requests without gamivo_id and stores it as null', function () {
        $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', validPayload())
            ->assertStatus(200);

        expect(Trade::latest('id')->firstOrFail()->lines->first()->gamivo_id)->toBeNull();
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
            ->assertJsonStructure(['profitable', 'total_tf2_price', 'is_added', 'last_commented_at', 'games_changed', 'should_comment']);

        expect($response->json('profitable'))->not->toBeEmpty();
    });

    it('returns empty profitable when no games are profitable', function () {
        $payload = validPayload(['games' => [
            ['name' => 'Junk Game', 'market_price_euro' => 0.05, 'popularity' => 1, 'region' => null],
        ]]);

        $response = $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', $payload)
            ->assertStatus(200)
            ->assertJsonStructure(['profitable', 'total_tf2_price', 'is_added', 'last_commented_at', 'games_changed', 'should_comment']);

        expect($response->json('profitable'))->toBeEmpty();
    });

    it('returns total_tf2_price as the sum of the profitable games tf2_price', function () {
        // €4.50 → 2.46 ; €10.00 → 5.45 ; soma = 7.91
        $payload = validPayload(['games' => [
            ['name' => 'Cheap Game', 'market_price_euro' => 4.50, 'popularity' => 100, 'region' => null],
            ['name' => 'Pricey Game', 'market_price_euro' => 10.00, 'popularity' => 100, 'region' => null],
        ]]);

        $response = $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', $payload)
            ->assertStatus(200);

        expect($response->json('total_tf2_price'))->toBe(7.91);
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
            ->assertJsonStructure(['profitable', 'total_tf2_price', 'is_added', 'last_commented_at', 'games_changed', 'should_comment']);

        expect($response->json('last_commented_at'))->toBeNull()
            ->and($response->json('games_changed'))->toBeFalse();
    });
});

// ── offer_currency ────────────────────────────────────────────────────────────

describe('POST /suppliers/prospect — offer_currency', function () {

    beforeEach(function () {
        Config::set('services.external_secret', PROSPECT_SECRET);
        seedProspectDeps();
        DB::table('assets')->where('name', 'TF2')->update(['price_dollar' => 1.10]);
    });

    function cashPayload(string $currency): array
    {
        return validPayload([
            'offer_currency' => $currency,
            'games' => [
                ['name' => 'Cheap Game', 'market_price_euro' => 4.50, 'popularity' => 100, 'region' => null],
                ['name' => 'Pricey Game', 'market_price_euro' => 10.00, 'popularity' => 100, 'region' => null],
            ],
        ]);
    }

    it('keeps the TF2 response untouched when the currency is absent or tf2', function () {
        foreach ([validPayload(), validPayload(['offer_currency' => 'tf2'])] as $payload) {
            $response = $this->withToken(PROSPECT_SECRET)->postJson('/suppliers/prospect', $payload)->assertStatus(200);

            expect($response->json())->not->toHaveKeys(['offer_currency', 'total_offer_price'])
                ->and($response->json('profitable.0'))->not->toHaveKey('offer_price');
        }
    });

    it('offers in euros: the TF2 offer times the euro price of the TF2', function () {
        $response = $this->withToken(PROSPECT_SECRET)->postJson('/suppliers/prospect', cashPayload('eur'))->assertStatus(200);

        $profitable = $response->json('profitable');

        expect($response->json('offer_currency'))->toBe('eur')
            ->and($profitable)->toHaveCount(2);

        foreach ($profitable as $game) {
            expect($game['offer_price'])->toBeFloat()
                ->and($game['offer_price'])->toEqualWithDelta($game['tf2_price'] * 0.95, 0.01);
        }

        expect($response->json('total_offer_price'))->toBe(round(array_sum(array_column($profitable, 'offer_price')), 2));
    });

    it('converts the exact TF2 offer, not the already rounded tf2_price', function () {
        // €15,00: renda 15 × 0,92 − 0,40 = 13,40 ; 13,40 / 1,7 = 7,882 → €7,88.
        // Via tf2_price arredondado (8,30 × 0,95) daria €7,89.
        $payload = validPayload([
            'offer_currency' => 'eur',
            'games' => [['name' => 'Big Game', 'market_price_euro' => 15.00, 'popularity' => 100, 'region' => null]],
        ]);

        $response = $this->withToken(PROSPECT_SECRET)->postJson('/suppliers/prospect', $payload)->assertStatus(200);

        expect($response->json('profitable.0.offer_price'))->toBe(7.88)
            ->and($response->json('profitable.0'))->not->toHaveKey('tf2_offer');
    });

    it('offers in dollars: the TF2 offer times the dollar price of the TF2', function () {
        $response = $this->withToken(PROSPECT_SECRET)->postJson('/suppliers/prospect', cashPayload('usd'))->assertStatus(200);

        $profitable = $response->json('profitable');

        expect($response->json('offer_currency'))->toBe('usd');

        foreach ($profitable as $game) {
            expect($game['offer_price'])->toEqualWithDelta($game['tf2_price'] * 1.10, 0.01);
        }
    });

    it('does not change which games are profitable', function () {
        $tf2 = $this->withToken(PROSPECT_SECRET)->postJson('/suppliers/prospect', cashPayload('tf2'))->json('profitable.*.name');
        $eur = $this->withToken(PROSPECT_SECRET)->postJson('/suppliers/prospect', cashPayload('eur'))->json('profitable.*.name');

        expect($eur)->toBe($tf2);
    });

    it('records the currency on the created trade', function () {
        $this->withToken(PROSPECT_SECRET)->postJson('/suppliers/prospect', cashPayload('usd'))->assertStatus(200);

        expect(Trade::latest('id')->first()->currency->value)->toBe('usd');
    });

    it('rejects an unknown currency instead of answering in TF2', function () {
        $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', cashPayload('gbp'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('offer_currency');
    });

    it('answers 503 and persists nothing when TF2 has no price in the currency', function () {
        DB::table('assets')->where('name', 'TF2')->update(['price_dollar' => 0]);
        Cache::flush();

        $this->withToken(PROSPECT_SECRET)->postJson('/suppliers/prospect', cashPayload('usd'))->assertStatus(503);

        // Nada gravado: nem a trade, nem o supplier.
        expect(Trade::count())->toBe(0)
            ->and(DB::table('suppliers')->where('steam_id', SUPPLIER_STEAM_ID)->exists())->toBeFalse();
    });

    it('does not fail for a missing price when there is no comment to post', function () {
        DB::table('assets')->where('name', 'TF2')->update(['price_dollar' => 0]);
        Cache::flush();

        $response = $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', validPayload([
                'offer_currency' => 'usd',
                'games' => [['name' => 'Junk Game', 'market_price_euro' => 0.05, 'popularity' => 1, 'region' => null]],
            ]))
            ->assertStatus(200);

        expect($response->json('should_comment'))->toBeFalse()
            ->and($response->json())->not->toHaveKey('offer_currency');
    });
});

// ── nome do preço de mercado ──────────────────────────────────────────────────

describe('POST /suppliers/prospect — market_price_euro', function () {

    beforeEach(function () {
        Config::set('services.external_secret', PROSPECT_SECRET);
        seedProspectDeps();
    });

    it('rejects the removed price_euro name with 422', function () {
        $payload = validPayload(['games' => [
            ['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => null],
        ]]);

        $this->withToken(PROSPECT_SECRET)
            ->postJson('/suppliers/prospect', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['games.0.market_price_euro']);
    });

    it('ignores a stray price_euro sent next to market_price_euro', function () {
        $payload = validPayload(['games' => [
            ['name' => 'Half-Life', 'market_price_euro' => 4.50, 'price_euro' => 99.0, 'popularity' => 500, 'region' => null],
        ]]);

        $response = $this->withToken(PROSPECT_SECRET)->postJson('/suppliers/prospect', $payload)->assertStatus(200);

        expect($response->json('profitable.0.market_price_euro'))->toBe(4.5);
    });

    it('no longer repeats price_euro in the response', function () {
        $response = $this->withToken(PROSPECT_SECRET)->postJson('/suppliers/prospect', validPayload())->assertStatus(200);

        expect($response->json('profitable.0'))->toHaveKey('market_price_euro')
            ->and($response->json('profitable.0'))->not->toHaveKey('price_euro');
    });
});
