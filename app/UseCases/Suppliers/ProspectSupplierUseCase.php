<?php

namespace App\UseCases\Suppliers;

use App\Domain\Pricing\IncomeCalculator;
use App\Domain\Pricing\OfferCalculator;
use App\Domain\Trades\CommentPolicy;
use App\Domain\Trades\TradeGameComparison;
use App\Models\Trade;
use App\Services\Keys\KeyCalculationService;
use App\Services\Suppliers\SupplierService;
use Carbon\Carbon;

class ProspectSupplierUseCase
{
    public function __construct(
        private readonly SupplierService $supplierService,
        private readonly KeyCalculationService $calculationService,
    ) {}

    /**
     * @param  array<int, array{name: string, price_euro: float, popularity: int, region: string|null}>  $games
     * @return array{profitable: array<int, mixed>, is_added: bool, last_commented_at: Carbon|null, games_changed: bool, should_comment: bool}
     */
    public function execute(string $steamId, array $games, ?string $listCode = null): array
    {
        $record = $this->supplierService->upsert([
            'steam_id' => $steamId,
            'url' => 'https://steamcommunity.com/profiles/'.$steamId,
        ]);

        $profitable = $this->evaluateProfitability($games);

        $previousTrade = $listCode
            ? Trade::where('list_code', $listCode)
                ->whereNotNull('last_commented_at')
                ->latest('last_commented_at')
                ->first()
            : null;

        $lastCommentedAt = $previousTrade?->last_commented_at;
        $previousRows = $previousTrade?->games ?? [];

        $gamesChanged = $previousTrade !== null
            && TradeGameComparison::hasChanged($games, $previousRows);

        $shouldComment = CommentPolicy::shouldComment($profitable, $gamesChanged, $lastCommentedAt);

        if ($shouldComment) {
            Trade::create([
                'supplier_id' => $record->id,
                'list_code' => $listCode,
                'last_commented_at' => now(),
                'date' => now()->format('Y-m-d'),
                'games' => $this->buildGames($profitable),
            ]);
        }

        return [
            'profitable' => $profitable,
            'is_added' => (bool) $record->is_added,
            'last_commented_at' => $lastCommentedAt,
            'games_changed' => $gamesChanged,
            'should_comment' => $shouldComment,
        ];
    }

    /**
     * @param  array<int, array{name: string, price_euro: float, popularity: int, region: string|null}>  $games
     * @return array<int, array{name: string, price_euro: float, popularity: int, region: string|null, tf2_price: float}>
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
                'tf2_price' => round($tf2Offer, 2),
            ];
        }

        return $profitable;
    }

    /**
     * @param  array<int, array{name: string, price_euro: float, popularity: int, region: string|null, tf2_price: float}>  $profitable
     * @return array<int, array<string, mixed>>
     */
    private function buildGames(array $profitable): array
    {
        return array_map(fn (array $game) => [
            'name' => $game['name'],
            'marketPriceRaw' => number_format($game['price_euro'], 2, '.', ''),
            'popularity' => (string) $game['popularity'],
            'regionLock' => $game['region'],
            'bundle' => null,
            'expiry' => null,
            'keyCode' => null,
        ], $profitable);
    }
}
