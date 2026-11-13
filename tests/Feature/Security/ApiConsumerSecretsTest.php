<?php

/*
|--------------------------------------------------------------------------
| ApiConsumerSecrets — cada consumidor com o seu segredo
|--------------------------------------------------------------------------
|
| As rotas de máquina aceitam só o Bearer do consumidor delas: o chatbot usa
| o CHATBOT_API_SECRET, o price_researcher o EXTERNAL_SECRET. É o que permite
| revogar o segredo do chatbot sem mexer no price_researcher — e um segredo
| que abrisse as rotas do outro anularia isso.
|
| As rotas são chamadas com um uuid que não existe: com o segredo certo a
| requisição passa da autenticação (404, 422...), com o errado para no 401.
|
*/

use App\Http\Middleware\VerifySecret;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

const CHATBOT_SECRET = 'the-chatbot-secret';
const PRICE_RESEARCHER_SECRET = 'the-price-researcher-secret';

beforeEach(function () {
    Config::set('services.chatbot_api_secret', CHATBOT_SECRET);
    Config::set('services.external_secret', PRICE_RESEARCHER_SECRET);
});

/**
 * As quatro rotas do chatbot, com o status que cada uma dá quando passa da
 * autenticação: o uuid não existe e o corpo vai vazio.
 */
function chatbotRoutes(): array
{
    $uuid = (string) Str::uuid();

    return [
        'read the trade' => ['GET', "/api/trades/{$uuid}", 404],
        'read the supplier trades' => ['GET', '/api/suppliers/76561198000000001/trades', 200],
        'counter offer' => ['POST', "/api/trades/{$uuid}/offers", 422],
        'message sent' => ['POST', "/api/trades/{$uuid}/message-sent", 404],
    ];
}

describe('chatbot routes', function () {

    it('opens with the chatbot secret', function (string $method, string $uri, int $pastAuthentication) {
        $this->withToken(CHATBOT_SECRET)->json($method, $uri)->assertStatus($pastAuthentication);
    })->with(chatbotRoutes());

    it('refuses the price_researcher secret', function (string $method, string $uri) {
        // O segredo de todos os serviços deixou de abrir as rotas do chatbot,
        // que dão acesso aos códigos de entrega.
        $this->withToken(PRICE_RESEARCHER_SECRET)->json($method, $uri)->assertStatus(401);
    })->with(chatbotRoutes());

    it('refuses everything when the chatbot secret is not configured', function (string $method, string $uri) {
        Config::set('services.chatbot_api_secret', null);

        $this->withToken(CHATBOT_SECRET)->json($method, $uri)->assertStatus(401);
        $this->json($method, $uri)->assertStatus(401);
    })->with(chatbotRoutes());
});

describe('price_researcher routes', function () {

    it('refuses the chatbot secret', function (string $method, string $uri) {
        $this->withToken(CHATBOT_SECRET)->json($method, $uri)->assertStatus(401);
    })->with([
        'create a trade with an offer' => ['POST', '/api/trades'],
        'prospect a supplier' => ['POST', '/suppliers/prospect'],
        'trade from a list or bundle research' => ['POST', '/trades/from-price-researcher'],
    ]);

    it('still opens with the price_researcher secret', function () {
        // Corpo vazio: passa da autenticação e para na validação.
        $this->withToken(PRICE_RESEARCHER_SECRET)->postJson('/api/trades', [])->assertStatus(422);
    });
});

it('refuses to guard a route for a consumer it does not know', function () {
    // Erro de quem declarou a rota, não do request: falha alto em vez de
    // abrir ou fechar a rota em silêncio.
    expect(fn () => app(VerifySecret::class)->handle(Request::create('/'), fn () => response('ok'), 'unknown'))
        ->toThrow(InvalidArgumentException::class);
});
