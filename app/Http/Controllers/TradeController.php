<?php

namespace App\Http\Controllers;

use App\Domain\Pricing\OfferCalculator;
use App\Http\Requests\ImportTradeKeysRequest;
use App\Models\Trade;
use App\Services\Keys\KeyCalculationService;
use App\UseCases\Keys\RegisterKeyUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TradeController extends Controller
{
    public function __construct(
        private readonly KeyCalculationService $calculationService,
        private readonly RegisterKeyUseCase $registerKeyUseCase,
    ) {}

    /**
     * Página principal — carrega todas as trades persistidas e as props de cálculo.
     */
    public function show(): Response
    {
        $fee = $this->calculationService->getMarketplaceFee();

        return Inertia::render('Trades', [
            'trades' => Trade::orderBy('created_at')->get(['id', 'title', 'rows', 'created_at']),
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

    /**
     * Cria uma nova trade a partir das linhas coladas no frontend.
     */
    public function store(Request $request): JsonResponse
    {
        $trade = Trade::create(['rows' => $request->input('rows', [])]);

        return response()->json([
            'id' => $trade->id,
            'created_at' => $trade->created_at,
        ], 201);
    }

    /**
     * Persiste as alterações feitas nas células da tabela (autosave).
     */
    public function update(Request $request, Trade $trade): JsonResponse
    {
        $trade->update([
            'title' => $request->input('title'),
            'rows' => $request->input('rows', []),
        ]);

        return response()->json([], 200);
    }

    /**
     * Remove uma trade do sistema (ação manual do usuário).
     */
    public function destroy(Trade $trade): JsonResponse
    {
        $trade->delete();

        return response()->json([], 204);
    }

    /**
     * Importa as keys de uma trade para o estoque.
     */
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
