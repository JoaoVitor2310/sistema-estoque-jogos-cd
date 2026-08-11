<?php

namespace App\Services\External;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Converte valores entre moedas (BRL, USD, EUR) via AwesomeAPI.
 * Infraestrutura pura — sem lógica de negócio.
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
            $response = Http::withHeaders([
                'x-api-key' => env('API_KEY_AWESOME_API'),
            ])->withOptions([
                'verify' => false,
            ])->get('https://economia.awesomeapi.com.br/json/last/USD-BRL,EUR-BRL');

            if (! $response->successful()) {
                $response->throw();
            }

            $data = $response->json();

            // Taxas sempre em relação ao BRL (média de alta e baixa)
            $rates = [
                'BRL' => 1.0,
                'USD' => ((float) $data['USDBRL']['high'] + (float) $data['USDBRL']['low']) / 2,
                'EUR' => ((float) $data['EURBRL']['high'] + (float) $data['EURBRL']['low']) / 2,
            ];

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
}
