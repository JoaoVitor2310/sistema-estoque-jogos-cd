<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/**
 * Reativa a verificação de CSRF **nas rotas de entrega**.
 *
 * O projeto desliga CSRF globalmente (`bootstrap/app.php`,
 * `validateCsrfTokens(except: ['*'])`), o que se sustentava enquanto toda
 * escrita exigia usuário autenticado da equipe. A entrega é a primeira
 * superfície pública com sessão: por 12 horas, o navegador do supplier carrega
 * um cookie que autoriza gravar numa trade.
 *
 * `same_site=lax` já barra a requisição cross-site com cookie, mas ele é
 * configuração de ambiente (`SESSION_SAME_SITE`) e pode ser afrouxado por outro
 * motivo qualquer; a verificação de token não depende disso.
 *
 * O `$neverVerify` é **estático** na classe pai, e estático é compartilhado com
 * as subclasses: sem redeclarar aqui, esta classe herdaria o `['*']` global e
 * não verificaria nada.
 */
class ValidateDeliveryCsrfToken extends ValidateCsrfToken
{
    /** @var array<int, string> */
    protected static $neverVerify = [];
}
