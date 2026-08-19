<?php

namespace App\Http\Middleware;

use App\Models\Trade;
use App\Services\Trades\DeliverySession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Barra as escritas da entrega a quem não acertou o token daquela entrega — e
 * as escritas de qualquer um numa entrega que já foi fechada.
 *
 * Fica fora do `GET` da página de propósito: ela precisa responder sem sessão
 * para poder pedir o token. Quem decide o que a página mostra é o controller;
 * quem protege as gravações é este middleware.
 *
 * **A trade importada também é barrada aqui.** A credencial morre no import (a
 * página exibe as keys já preenchidas, então a janela de um link vazado vai até
 * ali), e sem esta checagem uma sessão ainda dentro das 12 horas continuaria
 * gravando numa trade já virada em estoque.
 */
class EnsureDeliverySession
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $trade = $request->route('trade');

        if (! $trade instanceof Trade || $trade->is_imported) {
            abort(404);
        }

        if (! DeliverySession::isOpen($request->session(), $trade)) {
            abort(403);
        }

        // Entrega feita é entrega fechada (ver docs/adr/0008): entre o clique
        // dele e o import da equipe existe uma janela de dias, e enquanto ela
        // durar as keys entregues são a única cópia que temos. 409 e não 403: a
        // sessão dele é válida, o que acabou foi a janela de escrita.
        if (! $trade->deliveryState()->acceptsSupplierWrites()) {
            abort(409, 'This delivery is already with our team. Message us if anything needs to change.');
        }

        return $next($request);
    }
}
