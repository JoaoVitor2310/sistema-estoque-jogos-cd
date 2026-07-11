<?php

namespace App\Http\Controllers;

use App\Domain\Pricing\OfferCalculator;
use App\Http\Requests\ImportTradeKeysRequest;
use App\Http\Requests\StoreListTradeRequest;
use App\Http\Requests\UpdateTradeRequest;
use App\Models\Trade;
use App\Services\Keys\KeyCalculationService;
use App\Services\Trades\TradeService;
use App\UseCases\Keys\RegisterKeyUseCase;
use App\UseCases\Trades\CreateTradeUseCase;
use App\UseCases\Trades\StoreListTradeUseCase;
use App\UseCases\Trades\UpdateTradeUseCase;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class TradeController extends Controller
{
    public function __construct(
        private readonly KeyCalculationService $calculationService,
        private readonly RegisterKeyUseCase $registerKeyUseCase,
        private readonly TradeService $tradeService,
        private readonly CreateTradeUseCase $createTradeUseCase,
        private readonly UpdateTradeUseCase $updateTradeUseCase,
        private readonly StoreListTradeUseCase $storeListTradeUseCase,
    ) {}

    public function show(): Response
    {
        $fee = $this->calculationService->getMarketplaceFee();

        return Inertia::render('Trades', [
            'trades' => $this->tradeService->allWithStockedStatus(),
            'tf2Price' => $this->calculationService->getTf2EuroPrice(),
            'fees' => [
                'percentLow' => $fee->percentLow,
                'fixedLow' => $fee->fixedLow,
                'percentHigh' => $fee->percentHigh,
                'fixedHigh' => $fee->fixedHigh,
            ],
            'profitTiers' => OfferCalculator::PROFIT_TIERS,
        ]);
    }

    public function store(): JsonResponse
    {
        $trade = $this->createTradeUseCase->execute([]);

        return response()->json([
            'id' => $trade->id,
            'title' => $trade->title,
            'created_at' => $trade->created_at,
        ], 201);
    }

    public function storeFromPriceResearcher(StoreListTradeRequest $request): JsonResponse
    {
        $trade = $this->storeListTradeUseCase->execute($request->validated());

        return response()->json(['created_at' => $trade->created_at], 201);
    }

    public function update(UpdateTradeRequest $request, Trade $trade): JsonResponse
    {
        $this->updateTradeUseCase->execute($trade, $request->validated());

        return response()->json([
            'is_stocked' => $this->tradeService->isStocked($trade->games ?? []),
        ], 200);
    }

    public function destroy(Trade $trade): JsonResponse
    {
        $trade->delete();

        return response()->json([], 204);
    }

    public function importKeys(ImportTradeKeysRequest $request, Trade $trade): JsonResponse
    {
        $result = $this->registerKeyUseCase->execute($request->validated('games'));

        $status = empty($result['errors']) ? 201 : 207;

        return response()->json([
            'message' => $result['message'],
            'errors' => $result['errors'],
            'count' => count($result['games']),
        ], $status);
    }
}
