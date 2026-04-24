<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;

/**
 * Infraestrutura de fornecedores — busca e criação no banco.
 * Zero lógica de negócio: apenas find-or-create pela URL do perfil.
 */
class SupplierService
{
    /**
     * Busca o fornecedor pela URL do perfil ou cria um novo.
     * Retorna o ID do fornecedor.
     */
    public function findOrCreate(string $supplierUrl): int
    {
        return Supplier::firstOrCreate(['supplier_url' => $supplierUrl])->id;
    }
}
