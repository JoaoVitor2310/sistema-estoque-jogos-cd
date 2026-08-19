<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarda das **páginas** da equipe: exige sessão e pertencer à equipe.
 *
 * Substituiu o antigo `RequireAuth`, que exigia só estar logado. Como
 * `/register` é aberto e não pede verificação de e-mail, "estar logado" era um
 * degrau que qualquer visitante subia em trinta segundos — e do outro dele
 * estavam o caixa da operação (`/financial-months`, `/assets`, `/sales`) e a
 * lista de quem tem acesso (`/acesso`). Nenhuma dessas telas mostra `key_code`,
 * mas nenhuma delas tem público fora da equipe.
 *
 * Duas respostas diferentes de propósito, porque são duas situações diferentes:
 * quem não tem sessão vai para o login, que é onde ele resolve o problema; quem
 * tem sessão e não é da equipe leva 403, porque para ele não há o que resolver.
 *
 * Par de [[CheckPermission]], que guarda as rotas de API/mutação com o mesmo
 * gate e responde 403 em JSON. A diferença entre os dois é só o formato da
 * recusa — a régua é a mesma.
 */
class RequireTeam
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        if (Gate::denies('can-edit')) {
            abort(403, 'Esta área é restrita à equipe.');
        }

        return $next($request);
    }
}
