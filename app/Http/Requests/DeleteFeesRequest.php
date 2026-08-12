<?php

namespace App\Http\Requests;

/**
 * Exclusão em lote enviada por Taxas.vue sob a chave `taxas`.
 */
class DeleteFeesRequest extends DeleteManyRequest
{
    protected function itemsKey(): string
    {
        return 'taxas';
    }

    protected function table(): string
    {
        return 'fees';
    }
}
