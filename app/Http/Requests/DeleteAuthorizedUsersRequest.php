<?php

namespace App\Http\Requests;

/**
 * Exclusão em lote enviada por Acesso.vue sob a chave `items`.
 */
class DeleteAuthorizedUsersRequest extends DeleteManyRequest
{
    protected function itemsKey(): string
    {
        return 'items';
    }

    protected function table(): string
    {
        return 'authorized_users';
    }
}
