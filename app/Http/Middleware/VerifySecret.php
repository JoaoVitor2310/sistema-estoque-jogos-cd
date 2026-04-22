<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica se o request de servico externo carrega o Bearer token correto.
 * O servico envia: Authorization: Bearer <VIP_WEBHOOK_SECRET>
 * hash_equals() previne timing attacks.
 */
class VerifySecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.vip_webhook.secret');
        $provided = $request->bearerToken();

        if (empty($expected) || empty($provided) || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
