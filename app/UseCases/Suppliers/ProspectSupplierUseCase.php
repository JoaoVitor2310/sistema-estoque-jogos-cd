<?php

namespace App\UseCases\Suppliers;

use App\Domain\Trades\CommentPolicy;
use App\Domain\Trades\TradeGameComparison;
use App\Models\Trade;
use App\Services\Suppliers\SupplierService;
use Carbon\Carbon;

class ProspectSupplierUseCase
{
    public function __construct(
        private readonly SupplierService $supplierService,
        private readonly EvaluateSupplierProfitabilityUseCase $evaluateSupplierProfitabilityUseCase,
    ) {}

    /**
     * @param  array{steam_id: string, url: string}  $supplier
     * @param  array<int, array{name: string, price_euro: float, popularity: int, region: string|null}>  $games
     * @return array{profitable: array<int, mixed>, is_added: bool, last_commented_at: Carbon|null, games_changed: bool, should_comment: bool}
     */
    public function execute(array $supplier, array $games, ?string $listCode = null): array
    {
        $record = $this->supplierService->upsert($supplier);
        $profitable = $this->evaluateSupplierProfitabilityUseCase->execute($games);

        $previousTrade = $listCode
            ? Trade::where('list_code', $listCode)
                ->whereNotNull('last_commented_at')
                ->latest('last_commented_at')
                ->first()
            : null;

        $lastCommentedAt = $previousTrade?->last_commented_at;
        $gamesChanged = $previousTrade !== null
            && TradeGameComparison::hasChanged($games, $previousTrade->rows);

        $shouldComment = CommentPolicy::shouldComment($profitable, $gamesChanged, $lastCommentedAt);

        if ($shouldComment) {
            Trade::create([
                'supplier_id' => $record->id,
                'list_code' => $listCode,
                'last_commented_at' => now(),
                'rows' => $this->buildRows($profitable, $supplier['url']),
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
     * @param  array<int, array{name: string, price_euro: float, popularity: int, region: string|null, tf2_price: float}>  $profitable
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(array $profitable, string $supplierUrl): array
    {
        $date = Carbon::now()->format('d/m/Y');

        return array_map(fn ($game) => [
            'date' => $date,
            'name' => $game['name'],
            'marketPriceRaw' => (string) $game['price_euro'],
            'tf2Qty' => (string) $game['tf2_price'],
            'popularity' => (string) $game['popularity'],
            'regionLock' => $game['region'],
            'supplierUrl' => $supplierUrl,
            'bundle' => null,
            'expiry' => null,
            'keyCode' => null,
        ], $profitable);
    }
}
