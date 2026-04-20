<?php

namespace App\UseCases\Vips;

use App\Models\Vip;
use App\Models\VipList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Orquestra a execução de listas VIP no price-researcher.
 *
 * Responsabilidades:
 *  - Validar pré-condição (id_steam presente)
 *  - Criar/atualizar VipList com status 'queued'
 *  - Enviar requisição HTTP ao price-researcher
 *  - Gerenciar transação (rollback se o serviço externo rejeitar)
 */
class ExecuteVipListUseCase
{
    /**
     * @return array{success: true, message: string, data: mixed}
     *       | array{success: false, code: int, message: string, data?: mixed}
     */
    public function execute(Vip $vip): array
    {
        if (!$vip->id_steam) {
            return [
                'success' => false,
                'code'    => 400,
                'message' => 'Não há id steam para executar',
            ];
        }

        DB::beginTransaction();

        try {
            $vipList = VipList::updateOrCreate(
                ['vip_id' => $vip->id],
                [
                    'status' => 'queued',
                    'result' => null,
                ]
            );

            $baseUrl      = rtrim(config('services.price_researcher.base_url'), '/');
            $callbackBase = rtrim(config('services.sistema-estoque.base_url'), '/');

            $response = Http::post($baseUrl . '/api/lists/run', [
                'id_steam'     => $vip->id_steam,
                'callback_url' => $callbackBase . '/vips/callback/' . $vipList->id,
            ]);

            $responseData = $response->json() ?? [];

            if ($response->failed()) {
                DB::rollBack();

                return [
                    'success' => false,
                    'code'    => 400,
                    'message' => 'Erro ao executar listas',
                    'data'    => $responseData,
                ];
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Listas executadas com sucesso',
                'data'    => $responseData,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
