<?php

namespace App\Http\Middleware;

use App\Traits\HttpResponses;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    use HttpResponses;
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */

    public function handle(Request $request, Closure $next): Response
    {
        if (Gate::denies('can-edit')) {
            return $this->error(403, 'Acesso negado. Você precisa estar logado e ser autorizado para realizar esta ação.');
        }

        return $next($request);
    }
}
