<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class APIService
{
    public function __construct() {}

    /**
     * Convert currency between BRL, EUR and USD
     * 
     * @param string $currentCurrency Source currency (BRL, EUR, USD)
     * @param string $futureCurrency Target currency (BRL, EUR, USD)
     * @param float $amount Amount to convert
     * @return array ['success' => bool, 'message' => string, 'amount' => float]
     */
    public function convertCurrency(string $currentCurrency, string $futureCurrency, float $amount)
    {
        // Se as moedas são iguais, não precisa converter
        if (strtoupper($currentCurrency) === strtoupper($futureCurrency)) {
            return ['success' => true, 'message' => 'Moedas iguais', 'amount' => $amount];
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => env('API_KEY_AWESOME_API')
            ])->withOptions([
                'verify' => env('APP_ENV') === 'production',
            ])->get('https://economia.awesomeapi.com.br/json/last/USD-BRL,EUR-BRL');

            // Verifica se a requisição foi bem-sucedida
            if ($response->successful()) {
                $data = $response->json();

                // Taxas de câmbio da API (sempre em relação ao BRL), média das taxas de alta e baixa
                $rates = [
                    'BRL' => 1.0,
                    'USD' => ((float) $data['USDBRL']['high'] + (float) $data['USDBRL']['low']) / 2,
                    'EUR' => ((float) $data['EURBRL']['high'] + (float) $data['EURBRL']['low']) / 2,
                ];

                $currentCurrency = strtoupper($currentCurrency);
                $futureCurrency = strtoupper($futureCurrency);

                // Valida se as moedas são suportadas
                if (!isset($rates[$currentCurrency]) || !isset($rates[$futureCurrency])) {
                    throw new \InvalidArgumentException('Moeda não suportada. Use: BRL, USD ou EUR');
                }

                // Converte para BRL primeiro (moeda base)
                $amountInBRL = $amount * $rates[$currentCurrency];

                // Depois converte de BRL para a moeda desejada
                $convertedAmount = $amountInBRL / $rates[$futureCurrency];

                return ['success' => true, 'message' => 'Conversão realizada com sucesso', 'amount' => round($convertedAmount, 3)];
            }

            $response->throw();
        } catch (\Exception $e) {
            Log::error('Erro na conversão de moeda: ' . $e->getMessage());

            // Retorna o valor original em caso de erro
            return ['success' => false, 'message' => 'Erro na conversão. ' . $e->getMessage(), 'amount' => $amount];
        }
    }

    /**
     * Get active game bundles from GGDeals API
     * 
     * @return array ['success' => bool, 'message' => string, 'data' => array]
     */
    public function getBundles()
    {
        try {
            $response = Http::timeout(180)
                ->withOptions([
                    'verify' => false
                ])
                ->get('http://api.gg.deals/v1/bundles/active/', [
                    'key' => config('services.ggdeals.api_key')
                ]);

            if (!$response->successful()) {
                Log::error('Erro na requisição à API GGDeals: HTTP ' . $response->status() . ' - ' . $response->body());
                return ['success' => false, 'message' => 'Erro na requisição à API: HTTP ' . $response->status(), 'data' => []];
            }

            $data = $response->json();
            if (!isset($data['data']['bundles'])) {
                Log::error('Resposta da API GGDeals não contém dados de bundles');
                return ['success' => false, 'message' => 'Resposta da API não contém dados de bundles', 'data' => []];
            }

            $data = $response->json();
            return ['success' => true, 'message' => 'Bundles obtidos com sucesso', 'data' => $data['data']['bundles']];
        } catch (\Exception $e) {
            Log::error('Erro na requisição à API GGDeals: ' . $e->getMessage() . ' - ' . $e->getMessage());
            return ['success' => false, 'message' => 'Erro na requisição à API GGDeals: ' . $e->getMessage(), 'data' => []];
        }
    }
}
