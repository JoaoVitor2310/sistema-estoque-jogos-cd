<?php

namespace App\Services;

use App\Models\VipList;

class VipListExecutionService
{
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
