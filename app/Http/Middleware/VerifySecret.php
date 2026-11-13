<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica se o request de serviço externo carrega o Bearer token correto.
 *
 * Cada consumidor tem o seu segredo, para um vazado ser revogado sem mexer nos
 * outros serviços:
 *
 * | Consumidor | Segredo | Rotas |
 * |---|---|---|
 * | `external` (padrão) | `EXTERNAL_SECRET` | as do price_researcher |
 * | `chatbot` | `CHATBOT_API_SECRET` | as que o chatbot lê e escreve em `/api` |
 *
 * Uma rota aceita **só** o segredo do seu consumidor: o do price_researcher
 * não abre as rotas do chatbot, e vice-versa. Segredo não configurado recusa
 * tudo. hash_equals() previne timing attacks.
 */
class VerifySecret
{
    public const EXTERNAL = 'external';

    public const CHATBOT = 'chatbot';

    /** Chave de configuração do segredo de cada consumidor. */
    private const SECRET_CONFIG = [
        self::EXTERNAL => 'services.external_secret',
        self::CHATBOT => 'services.chatbot_api_secret',
    ];

    /** O middleware das rotas do chatbot. */
    public static function chatbot(): string
    {
        return self::class.':'.self::CHATBOT;
    }

    public function handle(Request $request, Closure $next, string $consumer = self::EXTERNAL): Response
    {
        $configKey = self::SECRET_CONFIG[$consumer]
            ?? throw new \InvalidArgumentException("Unknown API consumer: {$consumer}");

        $expected = config($configKey);
        $provided = $request->bearerToken();

        if (empty($expected) || empty($provided) || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
