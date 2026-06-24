<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;

class SupplierService
{
    public function findOrCreate(string $supplierUrl): int
    {
        return Supplier::firstOrCreate(
            ['url' => $supplierUrl],
            ['has_traded' => true],
        )->id;
    }

    /**
     * @param  array{steam_id: string, url: string}  $data
     */
    public function upsert(array $data): Supplier
    {
        return Supplier::updateOrCreate(
            ['steam_id' => $data['steam_id']],
            ['url' => $data['url']],
        );
    }
}
