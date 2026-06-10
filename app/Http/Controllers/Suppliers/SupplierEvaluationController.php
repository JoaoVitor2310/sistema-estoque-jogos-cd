<?php

namespace App\Http\Controllers\Suppliers;

use App\Http\Controllers\Controller;
use App\Http\Requests\EvaluateSuppliersRequest;
use App\UseCases\Suppliers\EvaluateSupplierProfitabilityUseCase;
use Illuminate\Http\JsonResponse;

class SupplierEvaluationController extends Controller
{
    public function __construct(
        private readonly EvaluateSupplierProfitabilityUseCase $evaluateSupplierProfitabilityUseCase,
    ) {}

    /**
     * Avalia quais jogos de um fornecedor são rentáveis e retorna os valores em TF2.
     * Chamado pelo Price Researcher antes de postar comentário no SteamTrades.
     */
    public function evaluate(EvaluateSuppliersRequest $request): JsonResponse
    {
        $profitable = $this->evaluateSupplierProfitabilityUseCase->execute($request->validated('games'));

        return response()->json(['profitable' => $profitable]);
    }
}
