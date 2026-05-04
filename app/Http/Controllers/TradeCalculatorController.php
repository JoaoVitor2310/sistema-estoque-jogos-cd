<?php

namespace App\Http\Controllers;

use App\Domain\Pricing\OfferCalculator;
use App\Services\Keys\KeyCalculationService;
use App\UseCases\Keys\RegisterKeyUseCase;
use App\Http\Requests\ImportTradeKeysRequest;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class TradeCalculatorController extends Controller
{
    public function __construct(
        private readonly KeyCalculationService $calculationService,
        private readonly RegisterKeyUseCase $registerKeyUseCase,
    ) {}

    public function show(): Response
    {
        $fee = $this->calculationService->getMarketplaceFee();

        return Inertia::render('TradeCalculator', [
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

    public function import(ImportTradeKeysRequest $request): JsonResponse
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
