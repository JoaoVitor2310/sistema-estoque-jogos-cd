<?php

namespace App\Http\Controllers\Api;

use App\Domain\Enums\ChatDeliveryRefusal;
use App\Domain\Enums\CounterOfferRefusal;
use App\Domain\Pricing\CurrencyPriceUnavailable;
use App\Domain\Trades\ChatDeliveryRefused;
use App\Domain\Trades\CounterOfferRefused;
use App\Domain\Trades\RequestReusedForAnotherSupplier;
use App\Http\Controllers\Controller;
use App\Http\Requests\CounterOfferRequest;
use App\Http\Requests\FillTradeKeysRequest;
use App\Http\Requests\StoreTradeWithOfferRequest;
use App\Http\Resources\Api\ApiTradeResource;
use App\Http\Resources\Api\ApiTradeSummaryResource;
use App\Models\Trade;
use App\Services\Trades\ChatDeliveryQueries;
use App\Services\Trades\TradeApiReadModel;
use App\UseCases\Trades\CreateTradeWithOfferUseCase;
use App\UseCases\Trades\DeliverTradeFromChatUseCase;
use App\UseCases\Trades\FillTradeKeysFromChatUseCase;
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
        private readonly FillTradeKeysFromChatUseCase $fillTradeKeys,
        private readonly DeliverTradeFromChatUseCase $deliverTradeFromChat,
        private readonly ChatDeliveryQueries $chatDeliveryQueries,
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

    /** A trade de um supplier pelo número dela, no formato da leitura pelo uuid. */
    public function showForSupplier(string $steamId, int $number): JsonResponse
    {
        $trade = $this->readModel->findForSupplierByNumber($steamId, $number);

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
     * 201 com o uuid da trade (também na repetição do mesmo `request_uuid`); 200
     * `created: false` quando não há o que ofertar — não é erro, é a resposta
     * "nenhum jogo nos interessa"; 422 quando o `request_uuid` já criou a trade
     * de outro supplier.
     */
    public function store(StoreTradeWithOfferRequest $request): JsonResponse
    {
        try {
            $trade = $this->createTradeWithOffer->execute($request->toDTO());
        } catch (CurrencyPriceUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (RequestReusedForAnotherSupplier $e) {
            // 422, no formato de validação: o pedido em si é inválido, e o
            // price_researcher não deve tentar de novo.
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['request_uuid' => [$e->getMessage()]],
            ], 422);
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
     * As keys que o supplier colou no chat: 200 com as linhas da oferta que
     * ainda estão sem key — vazio quer dizer que a entrega pode ser confirmada.
     * A key nunca volta na resposta.
     */
    public function fillKeys(FillTradeKeysRequest $request, string $steamId, int $number): JsonResponse
    {
        try {
            $trade = $this->fillTradeKeys->execute($steamId, $number, $request->toDTO());
        } catch (ChatDeliveryRefused $e) {
            return $this->chatDeliveryRefused($e);
        }

        if ($trade === null) {
            return response()->json(['message' => 'Trade not found.'], 404);
        }

        return response()->json(['missing_line_ids' => $this->chatDeliveryQueries->linesWithoutKey($trade)]);
    }

    /**
     * O supplier confirmou a entrega no chat: 200 com a trade, já aguardando
     * conferência e com o total da oferta como valor acertado.
     */
    public function deliverFromChat(string $steamId, int $number): JsonResponse
    {
        try {
            $trade = $this->deliverTradeFromChat->execute($steamId, $number);
        } catch (ChatDeliveryRefused $e) {
            return $this->chatDeliveryRefused($e);
        }

        if ($trade === null) {
            return response()->json(['message' => 'Trade not found.'], 404);
        }

        return (new ApiTradeResource($this->readModel->findByUuid($trade->uuid)))->response();
    }

    /**
     * 422 para o pedido mal montado (linha fora da oferta: erro de quem chama, e
     * repetir não adianta); 409 para o estado da trade. As linhas que causaram
     * a recusa voltam junto, para o chatbot dizer ao supplier quais jogos faltam.
     */
    private function chatDeliveryRefused(ChatDeliveryRefused $e): JsonResponse
    {
        $status = $e->reason === ChatDeliveryRefusal::LineNotInOffer ? 422 : 409;
        $body = ['reason' => $e->reason->value];

        if ($e->lineIds !== []) {
            $body['line_ids'] = $e->lineIds;
        }

        return response()->json($body, $status);
    }

    /**
     * Registra que o supplier recebeu como entregar — o modelo do chat ou o
     * acesso à página —, o mesmo `message_sent` que a equipe marca na aba.
     * Repetir não muda nada.
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
