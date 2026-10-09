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

use App\Domain\Assets\ExchangeRatePolicy;
use App\Services\External\CurrencyConversionService;
use Illuminate\Support\Facades\Cache;
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

    describe('rate caching', function () {

        it('fetches the rates once for many conversions', function () {
            // Um sync de bundles converte dezenas de preços seguidos; a cotação
            // é a mesma, então só a primeira conversão vai à API.
            Http::fake(['economia.awesomeapi.com.br/*' => Http::response(awesomeApiQuotes(), 200)]);

            $service = app(CurrencyConversionService::class);
            foreach (range(1, 5) as $i) {
                $service->convertCurrency('USD', 'EUR', 10.00 * $i);
            }

            Http::assertSentCount(1);
        });

        it('fetches again once the fresh rates expire', function () {
            Http::fake(['economia.awesomeapi.com.br/*' => Http::response(awesomeApiQuotes(), 200)]);

            $service = app(CurrencyConversionService::class);
            $service->convertCurrency('USD', 'EUR', 10.00);
            Cache::forget(CurrencyConversionService::CACHE_KEY_FRESH);
            $service->convertCurrency('USD', 'EUR', 10.00);

            Http::assertSentCount(2);
        });

        it('falls back to the last good rates when the api fails', function () {
            // Timeout da AwesomeAPI derrubava o sync inteiro; com cotação de
            // poucas horas atrás a conversão ainda é boa o bastante.
            Http::fake(['economia.awesomeapi.com.br/*' => Http::sequence()
                ->push(awesomeApiQuotes(), 200)
                ->push([], 500)]);

            $service = app(CurrencyConversionService::class);
            $service->convertCurrency('EUR', 'USD', 10.00);
            Cache::forget(CurrencyConversionService::CACHE_KEY_FRESH);

            $result = $service->convertCurrency('EUR', 'USD', 10.00);

            expect($result['success'])->toBeTrue()
                ->and($result['amount'])->toBe(12.0);
        });

        it('refreshes the rates on its own after the fresh window', function () {
            Http::fake(['economia.awesomeapi.com.br/*' => Http::response(awesomeApiQuotes(), 200)]);

            $service = app(CurrencyConversionService::class);
            $service->convertCurrency('USD', 'EUR', 10.00);
            $this->travel(CurrencyConversionService::RATES_FRESH_TTL_SECONDS + 1)->seconds();
            $service->convertCurrency('USD', 'EUR', 10.00);

            Http::assertSentCount(2);
        });

        it('stops using the last good rates after six hours and fails the conversion', function () {
            // Passado o limite, a cotação velha demais distorceria preço e custo.
            Http::fake(['economia.awesomeapi.com.br/*' => Http::sequence()
                ->push(awesomeApiQuotes(), 200)
                ->push([], 500)]);

            $service = app(CurrencyConversionService::class);
            $service->convertCurrency('EUR', 'USD', 10.00);

            $this->travel(ExchangeRatePolicy::MAX_STALE_AGE_SECONDS + 1)->seconds();
            $result = $service->convertCurrency('EUR', 'USD', 10.00);

            expect($result['success'])->toBeFalse();
        });

        it('still uses the last good rates just inside the six hour limit', function () {
            Http::fake(['economia.awesomeapi.com.br/*' => Http::sequence()
                ->push(awesomeApiQuotes(), 200)
                ->push([], 500)]);

            $service = app(CurrencyConversionService::class);
            $service->convertCurrency('EUR', 'USD', 10.00);

            $this->travel(ExchangeRatePolicy::MAX_STALE_AGE_SECONDS - 1)->seconds();
            $result = $service->convertCurrency('EUR', 'USD', 10.00);

            expect($result['success'])->toBeTrue()
                ->and($result['amount'])->toBe(12.0);
        });

        it('does not hit the api again right after a failure without fallback', function () {
            // Sem reserva, cada conversão do sync pagava um timeout de 30s.
            Http::fake(['economia.awesomeapi.com.br/*' => Http::response([], 500)]);

            $service = app(CurrencyConversionService::class);
            $first = $service->convertCurrency('EUR', 'USD', 10.00);
            $second = $service->convertCurrency('EUR', 'USD', 10.00);

            expect($first['success'])->toBeFalse()
                ->and($second['success'])->toBeFalse();
            Http::assertSentCount(1);
        });

        it('tries the api again once the failure backoff is over', function () {
            Http::fake(['economia.awesomeapi.com.br/*' => Http::sequence()
                ->push([], 500)
                ->push(awesomeApiQuotes(), 200)]);

            $service = app(CurrencyConversionService::class);
            $service->convertCurrency('EUR', 'USD', 10.00);
            Cache::forget(CurrencyConversionService::CACHE_KEY_FAILURE);

            expect($service->convertCurrency('EUR', 'USD', 10.00)['success'])->toBeTrue();
        });
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
