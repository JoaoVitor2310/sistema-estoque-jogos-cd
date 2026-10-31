<?php

namespace App\Http\Controllers\Api;

use App\Domain\Pricing\CurrencyPriceUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTradeWithOfferRequest;
use App\Http\Resources\Api\ApiTradeResource;
use App\Http\Resources\Api\ApiTradeSummaryResource;
use App\Services\Trades\TradeApiReadModel;
use App\UseCases\Trades\CreateTradeWithOfferUseCase;
use Illuminate\Http\JsonResponse;

/**
 * Trades pela API, para os sistemas que integram com o Estoque.
 */
class TradeController extends Controller
{
    public function __construct(
        private readonly CreateTradeWithOfferUseCase $createTradeWithOffer,
        private readonly TradeApiReadModel $readModel,
    ) {}

    /** A trade pelo uuid, com a oferta vigente e o acesso à entrega. */
    public function show(string $uuid): JsonResponse
    {
        $trade = $this->readModel->findByUuid($uuid);

        if ($trade === null) {
            return response()->json(['message' => 'Trade not found.'], 404);
        }

        return (new ApiTradeResource($trade))->response();
    }

    /** As trades em negociação de um supplier, da mais recente para a mais antiga. */
    public function negotiatingForSupplier(string $steamId): JsonResponse
    {
        $trades = $this->readModel->negotiatingForSupplier($steamId);

        return response()->json([
            'trades' => ApiTradeSummaryResource::collection($trades)->resolve(),
        ]);
    }

    /**
     * 201 com o uuid da trade; 200 `created: false` quando não há o que ofertar
     * — não é erro, é a resposta "nenhum jogo nos interessa".
     */
    public function store(StoreTradeWithOfferRequest $request): JsonResponse
    {
        try {
            $trade = $this->createTradeWithOffer->execute($request->toDTO());
        } catch (CurrencyPriceUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        if ($trade === null) {
            return response()->json(['created' => false], 200);
        }

        return response()->json([
            'uuid' => $trade->uuid,
            // ISO 8601 com fuso, a mesma forma das leituras.
            'created_at' => $trade->created_at?->toIso8601String(),
            // A efetiva: TF2 quando não havia cotação na moeda pedida.
            'currency' => $trade->currency->value,
        ], 201);
    }
}
