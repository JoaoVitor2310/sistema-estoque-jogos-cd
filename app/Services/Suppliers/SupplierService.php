<?php

namespace App\Services\Suppliers;

use App\Models\Fornecedor;

/**
 * Infraestrutura de fornecedores — busca e criação no banco.
 * Zero lógica de negócio: apenas find-or-create pelo perfil de origem.
 */
class SupplierService
{
    /**
     * Busca o fornecedor pelo perfil de origem ou cria um novo.
     * Retorna o ID do fornecedor.
     */
    public function findOrCreate(string $perfilOrigem): int
    {
        return Fornecedor::firstOrCreate(['perfilOrigem' => $perfilOrigem])->id;
    }
}
