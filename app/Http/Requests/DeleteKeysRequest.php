<?php

namespace App\Http\Requests;

/**
 * Exclusão em lote enviada por Keys.vue sob a chave `games`.
 */
class DeleteKeysRequest extends DeleteManyRequest
{
    protected function itemsKey(): string
    {
        return 'games';
    }

    protected function table(): string
    {
        return 'keys';
    }
}
