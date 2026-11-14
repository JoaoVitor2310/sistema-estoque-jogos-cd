<?php

/*
|--------------------------------------------------------------------------
| InternalNetworkOnly — rotas de máquina só pela rede Docker
|--------------------------------------------------------------------------
|
| O nginx diz por qual porta a requisição entrou no parâmetro FastCGI
| ESTOQUE_ENTRY: `public` no bloco de carcadeals.com, `internal` na porta 8080.
| Aqui ele é simulado com withServerVariables, que é como o parâmetro chega ao
| PHP. A config do nginx que o grava é guardada em EnvironmentTest.
|
*/

use App\Http\Middleware\InternalNetworkOnly;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

const ENTRY_CHATBOT_SECRET = 'entry-chatbot-secret';
const ENTRY_PRICE_RESEARCHER_SECRET = 'entry-price-researcher-secret';

beforeEach(function () {
    Config::set('services.chatbot_api_secret', ENTRY_CHATBOT_SECRET);
    Config::set('services.external_secret', ENTRY_PRICE_RESEARCHER_SECRET);
});

/** Toda rota de máquina, com o segredo que a abriria pela porta interna. */
function machineRoutes(): array
{
    $uuid = (string) Str::uuid();

    return [
        'create a trade with an offer' => ['POST', '/api/trades', ENTRY_PRICE_RESEARCHER_SECRET],
        'read the trade' => ['GET', "/api/trades/{$uuid}", ENTRY_CHATBOT_SECRET],
        'read the supplier trades' => ['GET', '/api/suppliers/76561198000000001/trades', ENTRY_CHATBOT_SECRET],
        'counter offer' => ['POST', "/api/trades/{$uuid}/offers", ENTRY_CHATBOT_SECRET],
        'message sent' => ['POST', "/api/trades/{$uuid}/message-sent", ENTRY_CHATBOT_SECRET],
        'unknown path under /api' => ['GET', '/api/anything', ENTRY_CHATBOT_SECRET],
        'prospect a supplier' => ['POST', '/suppliers/prospect', ENTRY_PRICE_RESEARCHER_SECRET],
        'trade from a list or bundle research' => ['POST', '/trades/from-price-researcher', ENTRY_PRICE_RESEARCHER_SECRET],
    ];
}

describe('through the public domain', function () {

    it('answers 404 on every machine route, even with the right secret', function (string $method, string $uri, string $secret) {
        // Com o segredo certo, para provar que o 404 vem da entrada e não da
        // autenticação: o domínio público não revela nem que a rota existe.
        $this->withServerVariables([InternalNetworkOnly::ENTRY_PARAM => InternalNetworkOnly::PUBLIC_ENTRY])
            ->withToken($secret)
            ->json($method, $uri)
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Not found.']);
    })->with(machineRoutes());

    it('keeps serving the screens of the team', function () {
        $this->withServerVariables([InternalNetworkOnly::ENTRY_PARAM => InternalNetworkOnly::PUBLIC_ENTRY])
            ->get('/login')
            ->assertOk();
    });

    it('leaves the auto-sell route open until it is known who calls it', function () {
        // Fora do bloqueio de propósito: ver docs/IMPROVEMENTS.md.
        $this->withServerVariables([InternalNetworkOnly::ENTRY_PARAM => InternalNetworkOnly::PUBLIC_ENTRY])
            ->getJson('/keys/auto-sell')
            ->assertStatus(401);
    });
});

describe('through the internal port', function () {

    it('reaches the authentication of every machine route', function (string $method, string $uri) {
        // Sem token: o 401 prova que a entrada interna passa e a rota segue
        // protegida pelo segredo. O caminho desconhecido segue no 404 dele.
        $expected = $uri === '/api/anything' ? 404 : 401;

        $this->withServerVariables([InternalNetworkOnly::ENTRY_PARAM => 'internal'])
            ->json($method, $uri)
            ->assertStatus($expected);
    })->with(machineRoutes());
});

it('lets the request through when the nginx does not say where it came from', function () {
    // Sem o parâmetro — nginx ainda não recarregado, testes, `artisan serve` —
    // o bloqueio não liga. É o que permite subir o código antes da migração.
    $this->json('POST', '/suppliers/prospect')->assertStatus(401);
});
