<?php

namespace App\UseCases\Trades;

use App\Domain\Trades\DeliveryCredential;
use App\Domain\Trades\TradeLineBuilder;
use App\Models\Trade;
use App\Services\Bundles\BundleService;
use App\Services\Suppliers\SupplierService;
use App\Services\Trades\OverstockService;
use Illuminate\Support\Facades\DB;

class StoreListTradeUseCase
{
    public function __construct(
        private readonly SupplierService $supplierService,
        private readonly BundleService $bundleService,
        private readonly OverstockService $overstockService,
    ) {}

    /**
     * @param  array{supplier_steam_id?: string|null, title?: string|null, list_code?: string|null, games: array<int, array{name: string, price_euro: float, popularity: int, region: string|null, gamivo_id?: string|null}>}  $data
     */
    public function execute(array $data): Trade
    {
        $supplier = $this->supplierService->resolveBySteamId($data['supplier_steam_id'] ?? null);

        $names = array_column($data['games'], 'name');

        // Pesquisa disparada de um bundle: o `title` volta idêntico e diz de
        // que bundle os jogos vieram — saber vence adivinhar. Condicionado a
        // não haver supplier porque a lista comentada manda `title` também, e
        // ali ele é o nome da lista no SteamTrades, não a origem das keys.
        $bundleMap = $supplier === null
            ? $this->bundleService->bundleByTitle($data['title'] ?? null, $names)
            : [];

        // Sem bundle nomeado pelo título, sobra o palpite por nome + recência.
        if ($bundleMap === []) {
            $bundleMap = $this->bundleService->recentBundleByGameNames($names);
        }

        // O jogo encalhado continua virando linha: aqui não há oferta a montar,
        // só o registro da lista pesquisada (docs/adr/0013).
        $lines = TradeLineBuilder::fromResearch(
            $this->overstockService->markResearched($data['games']),
            $bundleMap,
        );

        // Trade e linhas nascem juntas: uma trade sem as linhas pesquisadas
        // seria indistinguível de uma trade criada em branco.
        return DB::transaction(function () use ($data, $supplier, $lines) {
            $trade = Trade::create([
                'supplier_id' => $supplier?->id,
                'title' => ($data['title'] ?? null) ?: ($supplier?->name ?: null),
                'list_code' => $data['list_code'] ?? null,
                'date' => now()->format('Y-m-d'),
            ]);

            $trade->lines()->createMany($lines);

            // Toda trade nasce com credencial de entrega — ver docs/adr/0008.
            $trade->forceFill(DeliveryCredential::issue())->save();

            return $trade;
        });
    }
}
