<?php

namespace App\Services;

use App\Models\Vip;
use App\Models\VipList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class VipListExecutionService
{
    /**
     * Enfileira execução das listas do VIP no price-researcher.
     *
     * @return array{success: true, message: string, data: mixed}|array{success: false, code: int, message: string, data?: mixed}
     */
    public function queueRunForVip(Vip $vip): array
    {
        $vip_id_steam = $vip->id_steam;

        if (!$vip_id_steam) {
            return [
                'success' => false,
                'code' => 400,
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

            $baseUrl = rtrim(config('services.price_researcher.base_url'), '/');
            $callbackBase = rtrim(config('services.sistema-estoque.base_url'), '/');

            $response = Http::post($baseUrl . '/api/lists/run', [
                'id_steam' => $vip_id_steam,
                'callback_url' => $callbackBase . '/vips/callback/' . $vipList->id,
            ]);

            $responseData = $response->json() ?? [];

            if ($response->failed()) {
                DB::rollBack();

                return [
                    'success' => false,
                    'code' => 400,
                    'message' => 'Erro ao executar listas',
                    'data' => $responseData,
                ];
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Listas executadas com sucesso',
                'data' => $responseData,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Persiste o retorno do price-researcher e atualiza o VIP.
     *
     * @param  array<string, mixed>  $payload
     */
    public function applyCallback(VipList $vipList, array $payload): void
    {
        $vipList->update([
            'status' => $payload['status'] ?? null,
            'result' => $payload['result'] ?? null,
        ]);

        $vipList->vip->update([
            'result_at' => now(),
        ]);
    }
}
