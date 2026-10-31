<?php

/*
|--------------------------------------------------------------------------
| POST /api/trades/{uuid}/offers e /message-sent — HTTP
|--------------------------------------------------------------------------
|
| A borda das escritas do chatbot: autenticação, validação, o código de cada
| recusa e o formato da resposta. A regra da contraproposta está em
| RequestCounterOfferUseCaseTest.
|
*/

use App\Domain\Enums\TradeCurrency;
use App\Models\Trade;
use App\UseCases\Trades\CreateTradeWithOfferUseCase;
use App\UseCases\Trades\DTO\TradeWithOfferDTO;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

const NEGOTIATION_API_SECRET = 'test-negotiation-api-secret';

function seedNegotiationApiDeps(): void
{
    Cache::flush();

    DB::table('fees')->insertOrIgnore([
        ['name' => 'gamivo_percent_low', 'preco' => 0.06, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_low', 'preco' => 0.25, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_percent_high', 'preco' => 0.08, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_high', 'preco' => 0.40, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('assets')->insert([
        'name' => 'TF2', 'price_euro' => 0.95, 'price_dollar' => 0.0, 'price_brl' => 0.0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function negotiationTrade(): Trade
{
    return app(CreateTradeWithOfferUseCase::class)->execute(new TradeWithOfferDTO(
        '76561198000000654',
        [['name' => 'Half-Life', 'market_price_euro' => 4.50, 'popularity' => 500, 'region' => null, 'gamivo_id' => null]],
        TradeCurrency::Tf2,
        null,
        null,
    ));
}

function counterRequest(string $uuid, mixed $requested)
{
    return test()->withToken(NEGOTIATION_API_SECRET)->postJson("/api/trades/{$uuid}/offers", ['requested_total' => $requested]);
}

beforeEach(function () {
    Config::set('services.external_secret', NEGOTIATION_API_SECRET);
    seedNegotiationApiDeps();
});

describe('POST /api/trades/{uuid}/offers', function () {

    it('answers 201 with the trade and its new round', function () {
        $trade = negotiationTrade();

        $response = counterRequest($trade->uuid, 100)->assertStatus(201);

        expect($response->json('uuid'))->toBe($trade->uuid)
            ->and($response->json('offer.round'))->toBe(2)
            ->and($response->json('offer.is_final'))->toBeFalse()
            ->and($response->json('delivery_code'))->not->toBeNull();
    });

    it('answers 409 with the reason after the final offer', function () {
        $trade = negotiationTrade();
        counterRequest($trade->uuid, 100);
        counterRequest($trade->uuid, 100)->assertJsonPath('offer.is_final', true);

        counterRequest($trade->uuid, 100)->assertStatus(409)->assertExactJson(['reason' => 'offer_is_final']);
    });

    it('answers 409 when the trade is no longer in negotiation', function () {
        $trade = negotiationTrade();
        DB::table('trades')->where('id', $trade->id)->update(['delivered_at' => now()]);

        counterRequest($trade->uuid, 100)->assertStatus(409)->assertExactJson(['reason' => 'trade_not_negotiating']);
    });

    it('answers 409 when there is nothing left to concede', function () {
        $trade = negotiationTrade();
        DB::table('assets')->update(['price_euro' => 2.00]);
        Cache::flush();

        counterRequest($trade->uuid, 100)->assertStatus(409)->assertExactJson(['reason' => 'nothing_to_concede']);
    });

    it('answers 422 when the request is not above the current offer', function () {
        $trade = negotiationTrade();

        counterRequest($trade->uuid, 2.46)->assertStatus(422)->assertExactJson(['reason' => 'not_an_increase']);
    });

    it('validates the requested total', function (mixed $requested) {
        $trade = negotiationTrade();

        counterRequest($trade->uuid, $requested)->assertStatus(422)->assertJsonValidationErrors(['requested_total']);
    })->with([
        'missing' => [null],
        'zero' => [0],
        'negative' => [-5],
        'more than cents' => [5.123],
        'not a number' => ['five'],
    ]);

    it('answers 404 for an unknown or malformed uuid', function () {
        counterRequest('00000000-0000-4000-8000-000000000000', 100)->assertStatus(404);
        counterRequest('not-a-uuid', 100)->assertStatus(404);
    });

    it('answers 503 without the TF2 price in euro', function () {
        $trade = negotiationTrade();
        DB::table('assets')->update(['price_euro' => 0.0]);
        Cache::flush();

        counterRequest($trade->uuid, 100)->assertStatus(503);
    });

    it('refuses a request without the secret', function () {
        $trade = negotiationTrade();

        $this->postJson("/api/trades/{$trade->uuid}/offers", ['requested_total' => 100])->assertStatus(401);
    });
});

describe('POST /api/trades/{uuid}/message-sent', function () {

    it('marks that the delivery access was sent, and repeating changes nothing', function () {
        $trade = negotiationTrade();

        $this->withToken(NEGOTIATION_API_SECRET)->postJson("/api/trades/{$trade->uuid}/message-sent")->assertStatus(204);
        $this->withToken(NEGOTIATION_API_SECRET)->postJson("/api/trades/{$trade->uuid}/message-sent")->assertStatus(204);

        expect($trade->fresh()->message_sent)->toBeTrue();
    });

    it('answers 404 for an unknown or deleted trade', function () {
        $trade = negotiationTrade();
        $trade->delete();

        $this->withToken(NEGOTIATION_API_SECRET)->postJson('/api/trades/00000000-0000-4000-8000-000000000000/message-sent')->assertStatus(404);
        $this->withToken(NEGOTIATION_API_SECRET)->postJson("/api/trades/{$trade->uuid}/message-sent")->assertStatus(404);
    });

    it('refuses a request without the secret', function () {
        $trade = negotiationTrade();

        $this->postJson("/api/trades/{$trade->uuid}/message-sent")->assertStatus(401);
        expect($trade->fresh()->message_sent)->toBeFalse();
    });
});
