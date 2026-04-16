<?php

namespace App\Services\Keys;

use App\Models\Venda_chave_troca;

/**
 * Queries complexas sobre venda_chave_trocas.
 * Infraestrutura pura — sem lógica de negócio.
 */
class KeyRepository
{
    /**
     * Busca uma key pelo código de ativação.
     * Quando $excludeId é fornecido, ignora o próprio registro (útil no update).
     */
    public function findByKeyCode(string $keyCode, ?int $excludeId = null): ?Venda_chave_troca
    {
        return Venda_chave_troca::where('chaveRecebida', $keyCode)
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->first();
    }
}
