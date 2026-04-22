<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica se o request do webhook VIP carrega o Bearer token correto.
 *
 * O price_researcher envia o token via header:
 *   Authorization: Bearer <EXTERNAL_SECRET>
 *
 * hash_equals() previne timing attacks na comparação.
 * Em caso de falha, retorna 401 sem revelar o motivo específico.
 */
class VerifySecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.vip_webhook.secret');
        $provided = $request->bearerToken();

        if (empty($expected) || empty($provided) || !hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
