<?php

namespace App\UseCases\Trades;

use App\Models\Trade;
use App\Services\Suppliers\SupplierService;

class StoreListTradeUseCase
{
    public function __construct(
        private readonly SupplierService $supplierService,
    ) {}

    /**
     * @param  array{supplier_steam_id?: string|null, list_code?: string|null, games: array<int, array{name: string, price_euro: float, popularity: int, region: string|null}>}  $data
     */
    public function execute(array $data): Trade
    {
        $supplier = $this->supplierService->resolveBySteamId($data['supplier_steam_id'] ?? null);

        return Trade::create([
            'supplier_id' => $supplier?->id,
            'title' => ($data['title'] ?? null) ?: ($supplier?->name ?: null),
            'list_code' => $data['list_code'] ?? null,
            'date' => now()->format('Y-m-d'),
            'games' => $this->buildGames($data['games']),
        ]);
    }

    /**
     * @param  array<int, array{name: string, price_euro: float, popularity: int, region: string|null}>  $games
     * @return array<int, array<string, mixed>>
     */
    private function buildGames(array $games): array
    {
        return array_map(fn (array $game) => [
            'name' => $game['name'],
            'marketPriceRaw' => number_format($game['price_euro'], 2, '.', ''),
            'popularity' => (string) $game['popularity'],
            'regionLock' => $game['region'] ?? null,
            'bundle' => null,
            'expiry' => null,
            'keyCode' => null,
        ], $games);
    }
}
