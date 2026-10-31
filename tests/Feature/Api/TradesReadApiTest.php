<?php

/*
|--------------------------------------------------------------------------
| GET /api/trades/{uuid} e GET /api/suppliers/{steamId}/trades — HTTP
|--------------------------------------------------------------------------
|
| As leituras do chatbot: a trade com a oferta vigente e o acesso à entrega,
| e a lista das trades em negociação de um supplier. O que mais importa aqui
| é o que **não** sai — nada do que diz quanto o jogo vale para nós
| (docs/adr/0008 e docs/adr/0015).
|
*/

use App\Domain\Enums\TradeCurrency;
use App\Models\Trade;
use App\UseCases\Trades\CreateTradeWithOfferUseCase;
use App\UseCases\Trades\DTO\TradeWithOfferDTO;
use App\UseCases\Trades\RequestCounterOfferUseCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\GiveawayFactory;
use Tests\Support\TradeFactory;

const READ_API_SECRET = 'test-read-api-secret';
const READ_API_STEAM_ID = '76561198000000123';

/**
 * Valores internos que a API nunca pode devolver. Distintivos de propósito:
 * uuid e código de entrega são aleatórios, e um "999" ou "4.5" poderia sair
 * neles por acaso e derrubar o teste sem vazamento nenhum.
 */
const READ_API_GAMIVO_ID = 'GAMIVO-ID-INTERNO';
const READ_API_MARKET_PRICE = 7.77;

function seedReadApiDeps(): void
{
    Cache::flush();

    DB::table('fees')->insertOrIgnore([
        ['name' => 'gamivo_percent_low', 'preco' => 0.06, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_low', 'preco' => 0.25, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_percent_high', 'preco' => 0.08, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_high', 'preco' => 0.40, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('assets')->insert([
        'name' => 'TF2', 'price_euro' => 0.95, 'price_dollar' => 1.10, 'price_brl' => 0.0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Trade com a primeira oferta, pelo caminho de verdade. */
function readApiTrade(array $games, string $steamId = READ_API_STEAM_ID, TradeCurrency $currency = TradeCurrency::Tf2): Trade
{
    $games = array_map(fn (array $game) => $game + ['popularity' => 500, 'region' => null, 'gamivo_id' => READ_API_GAMIVO_ID], $games);

    return app(CreateTradeWithOfferUseCase::class)->execute(
        new TradeWithOfferDTO($steamId, $games, $currency, null, null)
    );
}

function readApi(string $uri)
{
    return test()->withToken(READ_API_SECRET)->getJson($uri);
}

beforeEach(function () {
    Config::set('services.external_secret', READ_API_SECRET);
    seedReadApiDeps();
});

describe('GET /api/trades/{uuid}', function () {

    it('shows the current offer, per game, and the delivery access', function () {
        $trade = readApiTrade([
            ['name' => 'Half-Life', 'market_price_euro' => 4.50, 'region' => 'EU'],
            ['name' => 'Portal', 'market_price_euro' => 6.00],
        ]);

        $json = readApi("/api/trades/{$trade->uuid}")->assertStatus(200)->json();

        expect($json['uuid'])->toBe($trade->uuid)
            ->and($json['currency'])->toBe('tf2')
            ->and($json['state'])->toBe('negotiating')
            ->and($json['offer']['round'])->toBe(1)
            ->and($json['offer']['is_final'])->toBeFalse()
            ->and($json['offer']['lines'])->toBe([
                ['game_name' => 'Half-Life', 'region' => 'EU', 'price' => 2.46],
                // Região vazia é key global
                ['game_name' => 'Portal', 'region' => '', 'price' => 3.34],
            ])
            ->and($json['offer']['total_price'])->toBe(5.8)
            ->and($json['delivery_url'])->toBe(route('deliveries.show', ['trade' => $trade->uuid]))
            ->and($json['delivery_code'])->toBe($trade->fresh()->readableDeliveryToken());
    });

    it('never shows what a game is worth to us, nor what was left out of the offer', function () {
        GiveawayFactory::active('Dwarven Realms');
        $trade = readApiTrade([
            ['name' => 'Half-Life', 'market_price_euro' => READ_API_MARKET_PRICE, 'popularity' => 4321],
            ['name' => 'Dwarven Realms', 'market_price_euro' => 9.99],
        ]);

        $response = readApi("/api/trades/{$trade->uuid}");
        $body = $response->getContent();

        // O jogo ofertado sai pelo valor da oferta, não pelo de mercado
        expect($response->json('offer.lines.0.price'))->not->toBe(READ_API_MARKET_PRICE);

        expect(array_keys($response->json()))->toBe(['uuid', 'created_at', 'currency', 'state', 'offer', 'delivery_url', 'delivery_code'])
            ->and(array_keys($response->json('offer')))->toBe(['round', 'is_final', 'created_at', 'total_price', 'lines'])
            ->and(array_keys($response->json('offer.lines.0')))->toBe(['game_name', 'region', 'price'])
            ->and($body)->not->toContain('Dwarven Realms')
            ->and($body)->not->toContain((string) READ_API_MARKET_PRICE)
            ->and($body)->not->toContain('4321')
            ->and($body)->not->toContain(READ_API_GAMIVO_ID)
            ->and($body)->not->toContain('profit')
            ->and($body)->not->toContain('market');
    });

    it('shows the offer of the highest round', function () {
        $trade = readApiTrade([['name' => 'Half-Life', 'market_price_euro' => 4.50]]);

        // A contraproposta pelo caminho de verdade: rodada 2, no alvo de 60%
        app(RequestCounterOfferUseCase::class)->execute($trade->uuid, 10000);

        expect(readApi("/api/trades/{$trade->uuid}")->json('offer'))
            ->round->toBe(2)
            ->total_price->toBe(2.61);
    });

    it('shows a trade without an offer, made by another channel, with the offer empty', function () {
        $trade = TradeFactory::withLines(['Half-Life'], ['uuid' => '6ec8ee77-f49b-4582-b11c-43ad7c1c5720']);

        expect(readApi("/api/trades/{$trade->uuid}")->assertStatus(200)->json('offer'))->toBeNull();
    });

    it('hides the delivery access once the keys are imported', function () {
        $trade = readApiTrade([['name' => 'Half-Life', 'market_price_euro' => 4.50]]);
        DB::table('trades')->where('id', $trade->id)->update(['is_imported' => true, 'delivered_at' => now()]);

        $json = readApi("/api/trades/{$trade->uuid}")->json();

        expect($json['state'])->toBe('imported')
            ->and($json['delivery_url'])->toBeNull()
            ->and($json['delivery_code'])->toBeNull();
    });

    it('keeps the delivery access while the delivery waits for review', function () {
        $trade = readApiTrade([['name' => 'Half-Life', 'market_price_euro' => 4.50]]);
        DB::table('trades')->where('id', $trade->id)->update(['delivered_at' => now()]);

        $json = readApi("/api/trades/{$trade->uuid}")->json();

        expect($json['state'])->toBe('awaiting_review')
            ->and($json['delivery_code'])->not->toBeNull();
    });

    it('answers 404 for an unknown, deleted or malformed uuid', function () {
        $trade = readApiTrade([['name' => 'Half-Life', 'market_price_euro' => 4.50]]);
        $trade->delete();

        readApi('/api/trades/00000000-0000-4000-8000-000000000000')->assertStatus(404);
        readApi("/api/trades/{$trade->uuid}")->assertStatus(404);
        readApi("/api/trades/{$trade->id}")->assertStatus(404);
    });

    it('refuses a request without the secret', function () {
        $trade = readApiTrade([['name' => 'Half-Life', 'market_price_euro' => 4.50]]);

        $this->getJson("/api/trades/{$trade->uuid}")->assertStatus(401);
    });
});

describe('GET /api/suppliers/{steamId}/trades', function () {

    it('lists the trades in negotiation, newest first, with the current offer summed up', function () {
        $older = readApiTrade([['name' => 'Half-Life', 'market_price_euro' => 4.50]]);
        DB::table('trades')->where('id', $older->id)->update(['created_at' => now()->subDay()]);
        $newer = readApiTrade([['name' => 'Portal', 'market_price_euro' => 6.00], ['name' => 'Quake', 'market_price_euro' => 12.00]]);

        $trades = readApi('/api/suppliers/'.READ_API_STEAM_ID.'/trades')->assertStatus(200)->json('trades');

        expect(array_column($trades, 'uuid'))->toBe([$newer->uuid, $older->uuid])
            ->and($trades[0]['games_count'])->toBe(2)
            ->and($trades[0]['total_price'])->toBe($newer->currentOffer->totalPrice())
            ->and($trades[1]['games_count'])->toBe(1);
    });

    it('leaves out delivered and imported trades, and other suppliers', function () {
        $open = readApiTrade([['name' => 'Half-Life', 'market_price_euro' => 4.50]]);
        $delivered = readApiTrade([['name' => 'Portal', 'market_price_euro' => 6.00]]);
        $imported = readApiTrade([['name' => 'Quake', 'market_price_euro' => 12.00]]);
        DB::table('trades')->where('id', $delivered->id)->update(['delivered_at' => now()]);
        DB::table('trades')->where('id', $imported->id)->update(['is_imported' => true, 'delivered_at' => now()]);
        readApiTrade([['name' => 'Doom', 'market_price_euro' => 5.00]], '76561198000000999');

        $trades = readApi('/api/suppliers/'.READ_API_STEAM_ID.'/trades')->json('trades');

        expect(array_column($trades, 'uuid'))->toBe([$open->uuid]);
    });

    it('never lists the delivery access', function () {
        readApiTrade([['name' => 'Half-Life', 'market_price_euro' => 4.50]]);

        $response = readApi('/api/suppliers/'.READ_API_STEAM_ID.'/trades');

        expect(array_keys($response->json('trades.0')))->toBe(['uuid', 'created_at', 'currency', 'games_count', 'total_price'])
            ->and($response->getContent())->not->toContain('deliveries');
    });

    it('answers an empty list for a supplier without open trades', function () {
        expect(readApi('/api/suppliers/76561198000000555/trades')->assertStatus(200)->json('trades'))->toBe([]);
    });

    it('answers 404 for something that is not a SteamID64', function () {
        readApi('/api/suppliers/carcadeals/trades')->assertStatus(404)->assertJson(['message' => 'Not found.']);
    });

    it('answers a JSON 404 for any unknown API path, never a redirect', function () {
        // Sem isto o Route::fallback global redireciona para /keys
        readApi('/api/nothing-here')->assertStatus(404)->assertJson(['message' => 'Not found.']);
        $this->postJson('/api/trades/extra')->assertStatus(404);
    });

    it('refuses a request without the secret', function () {
        $this->getJson('/api/suppliers/'.READ_API_STEAM_ID.'/trades')->assertStatus(401);
    });
});
