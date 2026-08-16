<?php

namespace App\Http\Controllers;

use App\Http\Requests\TradeLineRequest;
use App\Models\Trade;
use App\Models\TradeLine;
use App\UseCases\Trades\CreateTradeLineUseCase;
use App\UseCases\Trades\DeleteTradeLineUseCase;
use App\UseCases\Trades\UpdateTradeLineUseCase;
use Illuminate\Http\JsonResponse;

/**
 * As linhas de uma trade — recurso próprio, aninhado sob a trade.
 *
 * Separado de `TradeController` porque são entidades distintas: aquele cuida da
 * trade (listagem, campos, import), este de cada linha dentro dela.
 *
 * **Não remova o `Trade $trade` dos métodos que não o usam no corpo.** Ele é
 * quem faz o `scopeBindings` funcionar: o Laravel só escopa `{line}` pelo pai
 * se o pai estiver na assinatura do método — sem ele, `{line}` continua string
 * na rota, a linha volta a ser resolvida por id global, e a linha de uma trade
 * passa a ser editável e apagável pela URL de outra. Já verificado removendo:
 * o DELETE cruzado apaga a linha alheia em vez de devolver 404.
 */
class TradeLineController extends Controller
{
    public function __construct(
        private readonly CreateTradeLineUseCase $createTradeLine,
        private readonly UpdateTradeLineUseCase $updateTradeLine,
        private readonly DeleteTradeLineUseCase $deleteTradeLine,
    ) {}

    public function store(TradeLineRequest $request, Trade $trade): JsonResponse
    {
        $line = $this->createTradeLine->execute($trade, $request->toDTO());

        // A tela precisa do id para poder alterar e remover a linha em seguida,
        // e da posição porque uma inserção no meio empurrou as demais.
        return response()->json(['id' => $line->id, 'position' => $line->position], 201);
    }

    public function update(TradeLineRequest $request, Trade $trade, TradeLine $line): JsonResponse
    {
        $this->updateTradeLine->execute($line, $request->toDTO());

        return response()->json([], 200);
    }

    public function destroy(Trade $trade, TradeLine $line): JsonResponse
    {
        $this->deleteTradeLine->execute($line);

        return response()->json([], 204);
    }
}
