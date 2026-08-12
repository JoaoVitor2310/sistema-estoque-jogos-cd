<?php

namespace App\UseCases\Suppliers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pede ao price_researcher que varra a Steam atrás de fornecedores ainda não
 * cadastrados.
 *
 * A busca é assíncrona do outro lado: o serviço enfileira e responde na hora,
 * então o retorno aqui confirma o enfileiramento, não o resultado da varredura.
 */
class FindNewSuppliersUseCase
{
    /**
     * @return array{success: true, data: mixed}|array{success: false, code: int, message: string, data: mixed}
     */
    public function execute(): array
    {
        $baseUrl = rtrim(config('services.price_researcher.base_url'), '/');

        try {
            $response = Http::withToken(config('services.external_secret'))
                ->post($baseUrl.'/api/suppliers/find-new');
        } catch (ConnectionException $e) {
            // Serviço fora do ar não devolve status — sem este catch a exceção
            // sobe e vira 500 sem explicação (ver ResolveSteamIdsUseCase).
            Log::error('Price Researcher find-new-suppliers unreachable: '.$e->getMessage());

            return [
                'success' => false,
                'code' => 503,
                'message' => 'Serviço de busca de fornecedores indisponível.',
                'data' => [],
            ];
        }

        if ($response->failed()) {
            return [
                'success' => false,
                'code' => $response->status(),
                'message' => 'Erro ao buscar novos fornecedores.',
                'data' => $response->json(),
            ];
        }

        return ['success' => true, 'data' => $response->json()];
    }
}
