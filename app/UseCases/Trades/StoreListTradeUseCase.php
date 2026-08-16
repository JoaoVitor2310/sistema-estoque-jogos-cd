<?php

namespace App\UseCases\Trades;

use App\Domain\Trades\TradeLineBuilder;
use App\Models\Trade;
use App\Services\Bundles\BundleService;
use App\Services\Suppliers\SupplierService;
use Illuminate\Support\Facades\DB;

class StoreListTradeUseCase
{
    public function __construct(
        private readonly SupplierService $supplierService,
        private readonly BundleService $bundleService,
    ) {}

    /**
     * @param  array{supplier_steam_id?: string|null, title?: string|null, list_code?: string|null, games: array<int, array{name: string, price_euro: float, popularity: int, region: string|null, gamivo_id?: string|null}>}  $data
     */
    public function execute(array $data): Trade
    {
        $supplier = $this->supplierService->resolveBySteamId($data['supplier_steam_id'] ?? null);

        $names = array_column($data['games'], 'name');
        $bundleMap = $this->bundleService->recentBundleByGameNames($names);

        // Trade e linhas nascem juntas: uma trade sem as linhas pesquisadas
        // seria indistinguível de uma trade criada em branco.
        return DB::transaction(function () use ($data, $supplier, $bundleMap) {
            $trade = Trade::create([
                'supplier_id' => $supplier?->id,
                'title' => ($data['title'] ?? null) ?: ($supplier?->name ?: null),
                'list_code' => $data['list_code'] ?? null,
                'date' => now()->format('Y-m-d'),
            ]);

            $trade->lines()->createMany(TradeLineBuilder::fromResearch($data['games'], $bundleMap));

            return $trade;
        });
    }
}
