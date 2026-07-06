<?php

namespace App\UseCases\Suppliers;

use App\Models\Supplier;
use Illuminate\Support\Facades\Http;

class ExecuteSupplierListUseCase
{
    /**
     * @return array{success: true, message: string, data: mixed}
     *                                                            | array{success: false, code: int, message: string, data?: mixed}
     */
    public function execute(Supplier $supplier): array
    {
        if (! $supplier->steam_id) {
            return [
                'success' => false,
                'code' => 400,
                'message' => 'Não há id steam para executar',
            ];
        }

        $baseUrl = rtrim(config('services.price_researcher.base_url'), '/');

        $response = Http::post($baseUrl.'/api/lists/run', [
            'steam_id' => $supplier->steam_id,
        ]);

        $responseData = $response->json() ?? [];

        if ($response->failed()) {
            return [
                'success' => false,
                'code' => 400,
                'message' => 'Erro ao executar listas',
                'data' => $responseData,
            ];
        }

        return [
            'success' => true,
            'message' => 'Listas executadas com sucesso',
            'data' => $responseData,
        ];
    }
}
