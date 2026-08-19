<?php

namespace App\Http\Controllers;

use App\Domain\Enums\TradeLineAuthority;
use App\Http\Requests\DeliveryLineRequest;
use App\Http\Requests\DeliveryTokenRequest;
use App\Http\Requests\DeliveryTradeRequest;
use App\Http\Resources\DeliveryTradeResource;
use App\Models\Trade;
use App\Models\TradeLine;
use App\Services\Trades\DeliveryReadModel;
use App\Services\Trades\DeliverySession;
use App\UseCases\Trades\AuthenticateDeliveryUseCase;
use App\UseCases\Trades\MarkTradeDeliveredUseCase;
use App\UseCases\Trades\SubmitTradeDeliveryUseCase;
use App\UseCases\Trades\UpdateTradeLineUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A entrega da trade pelo próprio supplier (ver docs/adr/0008).
 *
 * **A única superfície do sistema cujo usuário não é a equipe.** Vive fora de
 * `CheckPermission` e de `RequireTeam`: quem autoriza é o token da entrega, e
 * nada aqui pode alcançar outra trade que não a do `{uuid}` da URL.
 *
 * Separado de [[TradeController]] de propósito, e não por tamanho: com dois
 * controllers, devolver preço ao supplier exige reescrever uma classe; com um
 * só e um `if`, basta inverter um booleano — e booleano invertido passa em code
 * review.
 */
class DeliveryController extends Controller
{
    public function __construct(
        private readonly DeliveryReadModel $readModel,
        private readonly AuthenticateDeliveryUseCase $authenticateDelivery,
        private readonly UpdateTradeLineUseCase $updateTradeLine,
        private readonly SubmitTradeDeliveryUseCase $submitDelivery,
        private readonly MarkTradeDeliveredUseCase $markDelivered,
    ) {}

    /**
     * A página. Responde em três estados, e nenhum deles depende de o visitante
     * estar autenticado no sistema.
     */
    public function show(Request $request, Trade $trade): Response
    {
        // Depois do import a credencial morreu. Mensagem neutra em vez de 404:
        // quem chega aqui já tinha o link, e um 404 só produziria o chamado "o
        // link que vocês mandaram quebrou".
        if ($trade->is_imported) {
            return Inertia::render('Delivery', ['state' => 'completed']);
        }

        if (! DeliverySession::isOpen($request->session(), $trade)) {
            // Sem token não vai dado nenhum — nem a contagem de jogos.
            return Inertia::render('Delivery', ['state' => 'locked']);
        }

        // `editable` sai daqui em vez de a página deduzir de `delivered_at`: a
        // regra de quando a entrega fecha é de domínio, e a aba do supplier não
        // é o lugar de reescrevê-la. Ela só desenha o que recebe — quem recusa
        // a escrita é o EnsureDeliverySession, não o `readonly` do input.
        return Inertia::render('Delivery', [
            'state' => 'open',
            'editable' => $trade->deliveryState()->acceptsSupplierWrites(),
            'trade' => new DeliveryTradeResource($this->readModel->loadLines($trade)),
        ]);
    }

    /**
     * Confere o token e abre a sessão daquela entrega.
     *
     * Os dois eixos de rate limit existem porque só por IP um atacante
     * distribui, e só por entrega dá para travar de propósito a entrega de um
     * supplier legítimo.
     */
    public function authenticate(DeliveryTokenRequest $request, Trade $trade): JsonResponse
    {
        if ($trade->is_imported) {
            abort(404);
        }

        $result = $this->authenticateDelivery->execute(
            $trade,
            $request->string('token')->toString(),
            $request->session(),
            $request->ip(),
        );

        return match ($result) {
            AuthenticateDeliveryUseCase::RESULT_OK => response()->json([], 200),
            AuthenticateDeliveryUseCase::RESULT_RATE_LIMITED => $this->rateLimited(
                $this->authenticateDelivery->retryAfter($trade, $request->ip()),
            ),
            default => response()->json(['message' => 'That code is not right.'], 422),
        };
    }

    /**
     * O 429 com o tempo de espera, no header e no texto.
     *
     * A janela é de uma hora: sem o número, "tente de novo mais tarde" faz o
     * supplier voltar em cinco minutos, levar 429 de novo e concluir que o link
     * quebrou.
     */
    private function rateLimited(int $retryAfter): JsonResponse
    {
        $minutes = max(1, (int) ceil($retryAfter / 60));

        return response()->json([
            'message' => "Too many attempts. Try again in {$minutes} ".($minutes === 1 ? 'minute' : 'minutes').'.',
        ], 429)->header('Retry-After', (string) $retryAfter);
    }

    /**
     * Os campos da própria trade — `tf2_qty` e a observação livre.
     */
    public function updateTrade(DeliveryTradeRequest $request, Trade $trade): JsonResponse
    {
        $this->submitDelivery->execute($trade, $request->toDTO());

        return response()->json([], 200);
    }

    /**
     * Uma linha, endereçada pela PK.
     *
     * Endereçar por posição seria um bug silencioso: se a equipe apagasse uma
     * linha na aba enquanto esta página está aberta, o índice enviado apontaria
     * para outro jogo e a key entraria no jogo errado, sem nada falhar. Com PK,
     * linha removida vira 404.
     */
    public function updateLine(DeliveryLineRequest $request, Trade $trade, TradeLine $line): JsonResponse
    {
        $this->updateTradeLine->execute($line, $request->toDTO(), TradeLineAuthority::Supplier);

        return response()->json([], 200);
    }

    /**
     * O botão de entregar, e o fim da janela de escrita do supplier.
     *
     * Só o primeiro clique chega aqui: a partir dele o middleware recusa toda
     * gravação desta entrega, inclusive um segundo `deliver`.
     *
     * O 422 é a entrega incompleta: sem o total de TF2 acertado o domínio não
     * aceita o envio, e a página fica aberta para ele preencher. Mensagem em
     * inglês e sem jargão nosso — quem lê é o supplier.
     */
    public function deliver(Trade $trade): JsonResponse
    {
        if (! $this->markDelivered->execute($trade)) {
            return response()->json([
                'message' => 'Tell us the total TF2 keys we agreed on before submitting.',
            ], 422);
        }

        return response()->json(['delivered_at' => $trade->delivered_at?->toIso8601String()], 200);
    }
}
