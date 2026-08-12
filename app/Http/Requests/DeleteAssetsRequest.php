<?php

namespace App\Http\Requests;

/**
 * Exclusão em lote enviada por Assets.vue sob a chave `assets`.
 */
class DeleteAssetsRequest extends DeleteManyRequest
{
    protected function itemsKey(): string
    {
        return 'assets';
    }

    protected function table(): string
    {
        return 'assets';
    }
}
