<?php

namespace App\Services\External;

use App\Domain\Assets\ExchangeRatePolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Converte valores entre moedas (BRL, USD, EUR) via AwesomeAPI.
 * Infraestrutura pura — sem lógica de negócio.
 *
 * A chave sai de `config('services.awesome_api.key')`, **nunca** de um `env()`
 * aqui: o deploy roda `config:cache` e, a partir daí, `env()` devolve null em
 * runtime. Sem chave a chamada cai no tier público, limitado por IP, e o
 * servidor leva 429 — o que se via na tela era o preço entrando sem converter,
 * só em produção. *(Já aconteceu.)*
 *
 * **Cotação em cache, não conversão.** O que se guarda são as taxas USD/EUR→BRL;
 * cada conversão é aritmética local. Antes era uma chamada HTTP por conversão,
 * então um sync de dezenas de bundles disparava dezenas de requests seguidos —
 * e, com a API fora do ar, um timeout de 30s por bundle. *(Já aconteceu.)*
 */
class CurrencyConversionService
{
    /**
     * Campo de preço correspondente a cada moeda suportada.
     */
    public const PRICE_FIELD_BY_CURRENCY = [
        'BRL' => 'price_brl',
        'EUR' => 'price_euro',
        'USD' => 'price_dollar',
    ];

    private const RATES_URL = 'https://economia.awesomeapi.com.br/json/last/USD-BRL,EUR-BRL';

    private const RATES_TIMEOUT_SECONDS = 10;

    /** Cotação fresca: igual ao `max-age` que a própria AwesomeAPI declara. */
    public const RATES_FRESH_TTL_SECONDS = 300;

    /** Após uma falha sem cotação de reserva, não insiste na API por este tempo. */
    public const RATES_FAILURE_BACKOFF_SECONDS = 60;

    public const CACHE_KEY_FRESH = 'currency_rates:fresh';

    public const CACHE_KEY_STALE = 'currency_rates:stale';

    public const CACHE_KEY_FAILURE = 'currency_rates:failure';

    /**
     * Converte um valor para as três moedas do sistema de uma vez.
     *
     * **Só devolve o que converteu.** `convertCurrency` responde com o valor de
     * entrada quando a API falha, então incluir a moeda mesmo assim faria
     * `price_dollar` valer o montante em real — número plausível e errado, que
     * o chamador não teria como distinguir de uma cotação real. Omitir a chave
     * obriga a tratar a ausência.
     *
     * A moeda de origem sempre está presente: ela não passa por conversão.
     *
     * @param  string  $from  BRL, USD ou EUR
     * @return array<string, float> subconjunto de price_brl / price_euro / price_dollar
     */
    public function convertAll(string $from, float $amount): array
    {
        $prices = [];

        foreach (self::PRICE_FIELD_BY_CURRENCY as $currency => $field) {
            $converted = $this->convertCurrency($from, $currency, $amount);

            if ($converted['success']) {
                $prices[$field] = (float) $converted['amount'];
            }
        }

        return $prices;
    }

    /**
     * Converte um valor entre duas moedas.
     *
     * @return array{success: bool, message: string, amount: float}
     */
    public function convertCurrency(string $from, string $to, float $amount): array
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        // Moedas iguais — sem chamada HTTP
        if ($from === $to) {
            return ['success' => true, 'message' => 'Moedas iguais', 'amount' => $amount];
        }

        try {
            $rates = $this->getRates();

            if (! isset($rates[$from]) || ! isset($rates[$to])) {
                throw new \InvalidArgumentException("Moeda não suportada. Use: BRL, USD ou EUR. Recebeu: {$from} → {$to}");
            }

            // Converte para BRL (moeda base) e depois para a moeda destino
            $converted = ($amount * $rates[$from]) / $rates[$to];

            return ['success' => true, 'message' => 'Conversão realizada com sucesso', 'amount' => round($converted, 3)];
        } catch (\Exception $e) {
            Log::error('Erro na conversão de moeda: '.$e->getMessage());

            return ['success' => false, 'message' => 'Erro na conversão. '.$e->getMessage(), 'amount' => $amount];
        }
    }

    /**
     * Taxas em relação ao BRL (média de alta e baixa): cache fresco, depois API,
     * depois a última cotação boa. Lança se nenhuma das três existir.
     *
     * @return array<string, float>
     */
    private function getRates(): array
    {
        $fresh = Cache::get(self::CACHE_KEY_FRESH);

        if ($fresh !== null) {
            return $fresh;
        }

        $stale = Cache::get(self::CACHE_KEY_STALE);

        // Falhou há pouco e não há reserva: falha rápido em vez de pagar outro timeout.
        if ($stale === null && Cache::has(self::CACHE_KEY_FAILURE)) {
            throw new \RuntimeException('Cotação indisponível (falha recente na AwesomeAPI)');
        }

        try {
            $rates = $this->fetchRates();
        } catch (\Exception $e) {
            if ($stale !== null) {
                // A conversão segue com a cotação de reserva, então só aqui a falha é logada à parte.
                Log::warning('AwesomeAPI indisponível, usando a última cotação boa: '.$e->getMessage());

                return $stale;
            }

            Cache::put(self::CACHE_KEY_FAILURE, true, self::RATES_FAILURE_BACKOFF_SECONDS);

            throw $e;
        }

        Cache::put(self::CACHE_KEY_FRESH, $rates, self::RATES_FRESH_TTL_SECONDS);
        Cache::put(self::CACHE_KEY_STALE, $rates, ExchangeRatePolicy::MAX_STALE_AGE_SECONDS);
        Cache::forget(self::CACHE_KEY_FAILURE);

        return $rates;
    }

    /**
     * @return array<string, float>
     */
    private function fetchRates(): array
    {
        $response = Http::withHeaders([
            'x-api-key' => config('services.awesome_api.key'),
        ])->withOptions([
            'verify' => false,
        ])->timeout(self::RATES_TIMEOUT_SECONDS)->get(self::RATES_URL);

        $response->throw();

        $data = $response->json();

        return [
            'BRL' => 1.0,
            'USD' => $this->midpoint($data['USDBRL']),
            'EUR' => $this->midpoint($data['EURBRL']),
        ];
    }

    /**
     * Cotação do par: média de alta e baixa do dia.
     *
     * @param  array{high: string, low: string}  $quote
     */
    private function midpoint(array $quote): float
    {
        return ((float) $quote['high'] + (float) $quote['low']) / 2;
    }
}
