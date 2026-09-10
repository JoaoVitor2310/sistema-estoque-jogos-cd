<?php

/*
|--------------------------------------------------------------------------
| CurrencyConversionServiceTest — unit tests
|--------------------------------------------------------------------------
|
| Cobre a conversão entre BRL, USD e EUR pela AwesomeAPI, e de onde sai a
| chave da API. Todos os requests são interceptados via Http::fake().
|
*/

use App\Services\External\CurrencyConversionService;
use Illuminate\Support\Facades\Http;

/** Resposta da AwesomeAPI com as duas cotações que o service usa. */
function awesomeApiQuotes(float $usd = 5.00, float $eur = 6.00): array
{
    return [
        'USDBRL' => ['high' => (string) ($usd + 0.10), 'low' => (string) ($usd - 0.10)],
        'EURBRL' => ['high' => (string) ($eur + 0.10), 'low' => (string) ($eur - 0.10)],
    ];
}

describe('CurrencyConversionService', function () {

    it('sends the api key from config, not from env', function () {
        // O deploy roda `config:cache`, e a partir daí `env()` devolve null em
        // runtime: a chave sumia só em produção e a chamada caía no tier
        // público, limitado por IP (429). É por isso que este teste existe.
        config(['services.awesome_api.key' => 'the-key']);

        Http::fake(['economia.awesomeapi.com.br/*' => Http::response(awesomeApiQuotes(), 200)]);

        app(CurrencyConversionService::class)->convertCurrency('EUR', 'USD', 10.00);

        Http::assertSent(fn ($request) => $request->header('x-api-key') === ['the-key']);
    });

    it('converts through BRL using the midpoint of high and low', function () {
        // EUR 6,00 e USD 5,00 no meio da faixa: 10 EUR = 60 BRL = 12 USD.
        Http::fake(['economia.awesomeapi.com.br/*' => Http::response(awesomeApiQuotes(), 200)]);

        $result = app(CurrencyConversionService::class)->convertCurrency('EUR', 'USD', 10.00);

        expect($result['success'])->toBeTrue()
            ->and($result['amount'])->toBe(12.0);
    });

    it('does not call the api when both currencies are the same', function () {
        Http::fake();

        $result = app(CurrencyConversionService::class)->convertCurrency('BRL', 'BRL', 42.00);

        expect($result['amount'])->toBe(42.00);
        Http::assertNothingSent();
    });

    it('reports failure and echoes the amount back when the api refuses', function () {
        // 429 é o que produção levava sem a chave. O montante volta como veio
        // para o chamador ter o que registrar — quem decide descartá-lo é o
        // convertAll abaixo.
        Http::fake(['economia.awesomeapi.com.br/*' => Http::response([], 429)]);

        $result = app(CurrencyConversionService::class)->convertCurrency('EUR', 'USD', 10.00);

        expect($result['success'])->toBeFalse()
            ->and($result['amount'])->toBe(10.00);
    });

    describe('convertAll()', function () {

        it('returns the three price fields when every conversion works', function () {
            Http::fake(['economia.awesomeapi.com.br/*' => Http::response(awesomeApiQuotes(), 200)]);

            $prices = app(CurrencyConversionService::class)->convertAll('EUR', 10.00);

            expect($prices)->toHaveKeys(['price_brl', 'price_euro', 'price_dollar'])
                ->and($prices['price_euro'])->toBe(10.00)
                ->and($prices['price_brl'])->toBe(60.00)
                ->and($prices['price_dollar'])->toBe(12.00);
        });

        it('omits what it could not convert, keeping only the source currency', function () {
            // Incluir a moeda que falhou faria `price_dollar` valer o montante
            // em euro — número plausível e errado, indistinguível de cotação.
            Http::fake(['economia.awesomeapi.com.br/*' => Http::response([], 429)]);

            $prices = app(CurrencyConversionService::class)->convertAll('EUR', 10.00);

            expect($prices)->toBe(['price_euro' => 10.00]);
        });
    });
});
