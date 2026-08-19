<?php

namespace App\UseCases\Suppliers;

use App\Domain\Pricing\IncomeCalculator;
use App\Domain\Pricing\OfferCalculator;
use App\Domain\Trades\CommentPolicy;
use App\Domain\Trades\DeliveryCredential;
use App\Domain\Trades\TradeGameComparison;
use App\Domain\Trades\TradeLineBuilder;
use App\Models\Trade;
use App\Services\Bundles\BundleService;
use App\Services\Keys\KeyCalculationService;
use App\Services\Suppliers\SupplierService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProspectSupplierUseCase
{
    public function __construct(
        private readonly SupplierService $supplierService,
        private readonly KeyCalculationService $calculationService,
        private readonly BundleService $bundleService,
    ) {}

    /**
     * @param  array<int, array{name: string, price_euro: float, popularity: int, region: string|null, gamivo_id?: string|null}>  $games
     * @return array{profitable: array<int, mixed>, total_tf2_price: float, is_added: bool, last_commented_at: Carbon|null, games_changed: bool, should_comment: bool}
     */
    public function execute(string $steamId, array $games, ?string $listCode = null): array
    {
        $record = $this->supplierService->upsert([
            'steam_id' => $steamId,
            'url' => 'https://steamcommunity.com/profiles/'.$steamId,
        ]);

        $profitable = $this->evaluateProfitability($games);

        $previousTrade = $listCode
            ? Trade::with('lines')
                ->where('list_code', $listCode)
                ->whereNotNull('last_commented_at')
                ->latest('last_commented_at')
                ->first()
            : null;

        $lastCommentedAt = $previousTrade?->last_commented_at;
        $previousNames = $previousTrade?->lines->pluck('game_name')->filter()->all() ?? [];

        $gamesChanged = $previousTrade !== null
            && TradeGameComparison::hasChanged(array_column($games, 'name'), $previousNames);

        $shouldComment = CommentPolicy::shouldComment($profitable, $gamesChanged, $lastCommentedAt);

        if ($shouldComment) {
            // Dentro do `if` e fora da transação: a prospecção avalia muitos
            // perfis e comenta poucos, então resolver antes cobraria uma query
            // por perfil avaliado em vez de por trade criada.
            $bundleMap = $this->bundleService->recentBundleByGameNames(array_column($profitable, 'name'));

            DB::transaction(function () use ($record, $listCode, $profitable, $bundleMap) {
                $trade = Trade::create([
                    'supplier_id' => $record->id,
                    'list_code' => $listCode,
                    'last_commented_at' => now(),
                    'date' => now()->format('Y-m-d'),
                ]);

                $trade->lines()->createMany(TradeLineBuilder::fromResearch($profitable, $bundleMap));

                // Toda trade nasce com credencial de entrega — ver docs/adr/0008.
                $trade->forceFill(DeliveryCredential::issue())->save();
            });
        }

        return [
            'profitable' => $profitable,
            'total_tf2_price' => round(array_sum(array_column($profitable, 'tf2_price')), 2),
            'is_added' => (bool) $record->is_added,
            'last_commented_at' => $lastCommentedAt,
            'games_changed' => $gamesChanged,
            'should_comment' => $shouldComment,
        ];
    }

    /**
     * @param  array<int, array{name: string, price_euro: float, popularity: int, region: string|null, gamivo_id?: string|null}>  $games
     * @return array<int, array{name: string, price_euro: float, popularity: int, region: string|null, gamivo_id: string|null, tf2_price: float}>
     */
    private function evaluateProfitability(array $games): array
    {
        $fee = $this->calculationService->getMarketplaceFee();
        $tf2Price = $this->calculationService->getTf2EuroPrice();

        $profitable = [];

        foreach ($games as $game) {
            $netIncome = IncomeCalculator::forGamivo((float) $game['price_euro'], $fee);
            $tf2Offer = OfferCalculator::tf2Offer($netIncome, OfferCalculator::NEW_SUPPLIER_PROFIT_PERCENT, $tf2Price);

            if ($tf2Offer <= 0) {
                continue;
            }

            $profitable[] = [
                'name' => $game['name'],
                'price_euro' => (float) $game['price_euro'],
                'popularity' => (int) $game['popularity'],
                'region' => $game['region'] ?? null,
                'gamivo_id' => $game['gamivo_id'] ?? null,
                'tf2_price' => round($tf2Offer, 2),
            ];
        }

        return $profitable;
    }
}
