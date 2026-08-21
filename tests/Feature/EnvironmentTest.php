<?php

/*
|--------------------------------------------------------------------------
| Test environment — guard
|--------------------------------------------------------------------------
|
| Guarda de regressão para um modo de falha que já custou caro diagnosticar:
| o compose define APP_ENV=local no ambiente do container (o entrypoint usa
| essa variável para escolher entre `npm run dev` e `npm run build`), e isso
| popula $_SERVER. O <env> do PHPUnit escreve em $_ENV/putenv, mas o
| ServerConstAdapter do Laravel lê $_SERVER primeiro — sem o <server> no
| phpunit.xml a suíte roda como `local`.
|
| O sintoma era distante da causa: ValidateCsrfToken::runningUnitTests()
| devolvia falso, as rotas de entrega (as únicas com CSRF religado, ver
| docs/adr/0008) passavam a exigir token de verdade, e 23 testes de trade
| delivery falhavam com 419 sem relação com o que se estava mexendo.
|
*/

it('runs in the testing environment', function () {
    // Se este teste falhar, o phpunit.xml perdeu o <server name="APP_ENV">.
    expect(app()->environment())->toBe('testing')
        ->and($_SERVER['APP_ENV'] ?? null)->toBe('testing');
});
