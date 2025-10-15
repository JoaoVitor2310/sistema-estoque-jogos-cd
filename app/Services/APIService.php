<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class APIService
{
    public function __construct() {}

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
}
