<?php

namespace App\Http\Controllers\Api;

use App\Domain\Enums\CounterOfferRefusal;
use App\Domain\Pricing\CurrencyPriceUnavailable;
use App\Domain\Trades\CounterOfferRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\CounterOfferRequest;
use App\Http\Requests\StoreTradeWithOfferRequest;
use App\Http\Resources\Api\ApiTradeResource;
use App\Http\Resources\Api\ApiTradeSummaryResource;
use App\Models\Trade;
use App\Services\Trades\TradeApiReadModel;
use App\UseCases\Trades\CreateTradeWithOfferUseCase;
use App\UseCases\Trades\RequestCounterOfferUseCase;
use Illuminate\Http\JsonResponse;

/**
 * Trades pela API, para os sistemas que integram com o Estoque.
 */
class TradeController extends Controller
{
    public function __construct(
        private readonly CreateTradeWithOfferUseCase $createTradeWithOffer,
        private readonly TradeApiReadModel $readModel,
        private readonly RequestCounterOfferUseCase $requestCounterOffer,
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

    /**
     * O supplier pediu mais: 201 com a trade e a nova rodada; 409 com o motivo
     * quando não há contraproposta (rodada final, nada a ceder, negociação
     * encerrada, trade sem oferta); 422 quando o pedido não passa da vigente.
     */
    public function counter(CounterOfferRequest $request, string $uuid): JsonResponse
    {
        try {
            $trade = $this->requestCounterOffer->execute($uuid, $request->requestedCents());
        } catch (CounterOfferRefused $e) {
            $status = $e->reason === CounterOfferRefusal::NotAnIncrease ? 422 : 409;

            return response()->json(['reason' => $e->reason->value], $status);
        } catch (CurrencyPriceUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        if ($trade === null) {
            return response()->json(['message' => 'Trade not found.'], 404);
        }

        // Relida pela mesma projeção da leitura: o que sai é sempre o mesmo formato.
        return (new ApiTradeResource($this->readModel->findByUuid($trade->uuid)))->response()->setStatusCode(201);
    }

    /**
     * Registra que o acesso à entrega foi mandado ao supplier — o mesmo
     * `message_sent` que a equipe marca na aba. Repetir não muda nada.
     */
    public function markMessageSent(string $uuid): JsonResponse
    {
        $updated = Trade::where('uuid', $uuid)->update(['message_sent' => true]);

        if ($updated === 0) {
            return response()->json(['message' => 'Trade not found.'], 404);
        }

        return response()->json(null, 204);
    }
}
