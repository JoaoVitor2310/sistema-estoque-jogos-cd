<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP para a API GGDeals.
 * Infraestrutura pura — sem lógica de negócio.
 *
 * Conversão de moedas foi extraída para Services/External/CurrencyConversionService.
 */
class APIService
{
    /**
     * Busca bundles ativos na API GGDeals.
     *
     * @return array{success: bool, message: string, data: array}
     */
    public function getBundles(): array
    {
        try {
            $response = Http::timeout(180)
                ->withOptions([
                    'verify' => false,
                ])
                ->get('http://api.gg.deals/v1/bundles/active/', [
                    'key' => config('services.ggdeals.api_key'),
                ]);

            if (! $response->successful()) {
                Log::error('Erro na requisição à API GGDeals: HTTP '.$response->status().' - '.$response->body());

                return ['success' => false, 'message' => 'Erro na requisição à API: HTTP '.$response->status(), 'data' => []];
            }

            $data = $response->json();

            if (! isset($data['data']['bundles'])) {
                Log::error('Resposta da API GGDeals não contém dados de bundles');

                return ['success' => false, 'message' => 'Resposta da API não contém dados de bundles', 'data' => []];
            }

            return ['success' => true, 'message' => 'Bundles obtidos com sucesso', 'data' => $data['data']['bundles']];
        } catch (\Exception $e) {
            Log::error('Erro na requisição à API GGDeals: '.$e->getMessage());

            return ['success' => false, 'message' => 'Erro na requisição à API GGDeals: '.$e->getMessage(), 'data' => []];
        }
    }
}
