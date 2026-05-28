<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAuth
{
    /**
     * Redireciona visitantes não autenticados para a página de login.
     * Usado em rotas de página (Inertia) que exigem autenticação.
     * Diferente do CheckPermission, que retorna JSON 403 para rotas de API.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}
