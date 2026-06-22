<?php

namespace App\UseCases\Suppliers;

use App\Services\Suppliers\SupplierService;

class ProspectSupplierUseCase
{
    public function __construct(
        private readonly SupplierService $supplierService,
        private readonly EvaluateSupplierProfitabilityUseCase $evaluateSupplierProfitabilityUseCase,
    ) {}

    /**
     * @param  array{steam_id: string, url: string, is_added: bool}  $supplier
     * @param  array<int, array{name: string, price_euro: float, popularity: int, region: string|null}>  $games
     * @return array{profitable: array<int, mixed>, is_tradable: bool}
     */
    public function execute(array $supplier, array $games): array
    {
        $this->supplierService->upsert($supplier);

        $profitable = $this->evaluateSupplierProfitabilityUseCase->execute($games);

        return [
            'profitable' => $profitable,
            'is_tradable' => count($profitable) > 0,
        ];
    }
}
