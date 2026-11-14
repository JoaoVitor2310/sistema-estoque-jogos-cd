<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rota de máquina só pela rede Docker: pelo domínio público, `404`.
 *
 * O nginx diz por qual porta a requisição entrou, no parâmetro FastCGI
 * `ESTOQUE_ENTRY` (`docker/nginx/`): `public` no bloco de carcadeals.com,
 * `internal` na porta 8080, que só a rede `sistema-estoque-cd` alcança. O
 * cliente não forja esse valor: header HTTP chega ao PHP como `HTTP_*`.
 *
 * A decisão mora aqui, e não num `location` do nginx, porque o caminho não é
 * confiável lá: `/index.php/api/...` não casa com `location /api/` e o Laravel
 * resolve para a mesma rota. Aqui ela é decidida pela rota, seja qual for o
 * caminho que chegou nela.
 *
 * Sem o parâmetro (nginx ainda não recarregado, testes, `artisan serve`), deixa
 * passar: o bloqueio liga com o reload, depois de os serviços migrarem para a
 * porta interna — ver docs/IMPROVEMENTS.md. Roda antes da autenticação, para o
 * domínio público não revelar nem que a rota existe.
 */
class InternalNetworkOnly
{
    /** Parâmetro FastCGI com a entrada da requisição. */
    public const ENTRY_PARAM = 'ESTOQUE_ENTRY';

    /** A entrada pelo domínio público. */
    public const PUBLIC_ENTRY = 'public';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->server(self::ENTRY_PARAM) === self::PUBLIC_ENTRY) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return $next($request);
    }
}
