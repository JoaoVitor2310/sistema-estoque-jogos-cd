<?php

namespace App\UseCases\Trades;

use App\Domain\Games\GameNameNormalizer;
use App\Models\Trade;
use App\Services\BundleService;
use App\Services\Suppliers\SupplierService;

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

        return Trade::create([
            'supplier_id' => $supplier?->id,
            'title' => ($data['title'] ?? null) ?: ($supplier?->name ?: null),
            'list_code' => $data['list_code'] ?? null,
            'date' => now()->format('Y-m-d'),
            'games' => $this->buildGames($data['games'], $bundleMap),
        ]);
    }

    /**
     * @param  array<int, array{name: string, price_euro: float, popularity: int, region: string|null, gamivo_id?: string|null}>  $games
     * @param  array<string, string>  $bundleMap  normalized game name → bundle name
     * @return array<int, array<string, mixed>>
     */
    private function buildGames(array $games, array $bundleMap): array
    {
        return array_map(fn (array $game) => [
            'name' => $game['name'],
            'marketPriceRaw' => number_format($game['price_euro'], 2, '.', ''),
            'popularity' => (string) $game['popularity'],
            'regionLock' => $game['region'] ?? null,
            'bundle' => $bundleMap[GameNameNormalizer::normalize($game['name'])] ?? null,
            'expiry' => null,
            'keyCode' => null,
            'gamivoId' => $game['gamivo_id'] ?? null,
        ], $games);
    }
}
