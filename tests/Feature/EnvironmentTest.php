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

/*
|--------------------------------------------------------------------------
| Resolução do APP_ENV no entrypoint do container
|--------------------------------------------------------------------------
|
| Guarda do incidente de 2026-08-24: o container de produção subiu com o shell
| em `local` — o Compose não interpolou `${APP_ENV}` — enquanto o `.env` dizia
| `production`. O entrypoint escolheu `npm run dev` pela variável de shell, a VPS
| ficou com um Vite dev server em polling sobre `vendor/` e um core preso até a
| máquina ficar inacessível por SSH.
|
| Desde então quem manda é o `.env`, e docker/resolve-app-env.sh é onde essa
| precedência mora. Estes testes exercitam o script de verdade, não uma cópia da
| lógica em PHP: o que quebrou em produção foi o parsing do shell.
|
*/

/**
 * Roda o resolvedor contra um `.env` temporário.
 *
 * @param  string|null  $envFileContents  conteúdo do arquivo; null = arquivo inexistente
 * @param  string|null  $shellValue  valor de APP_ENV no shell; null = variável ausente
 */
function resolveAppEnv(?string $envFileContents, ?string $shellValue = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'appenv');

    if ($envFileContents === null) {
        unlink($path);
    } else {
        file_put_contents($path, $envFileContents);
    }

    $prefix = $shellValue === null
        ? 'env -u APP_ENV'
        : 'env APP_ENV='.escapeshellarg($shellValue);

    $output = shell_exec(sprintf(
        '%s bash %s %s 2>/dev/null',
        $prefix,
        escapeshellarg(base_path('docker/resolve-app-env.sh')),
        escapeshellarg($path),
    ));

    if ($envFileContents !== null) {
        unlink($path);
    }

    return trim((string) $output);
}

it('reads the environment from the .env file', function () {
    expect(resolveAppEnv("APP_NAME=Estoque\nAPP_ENV=production\nAPP_DEBUG=false\n"))
        ->toBe('production');
});

it('prefers the .env file over the shell variable', function () {
    // O incidente exato: shell em `local`, arquivo em `production`.
    expect(resolveAppEnv("APP_ENV=production\n", shellValue: 'local'))
        ->toBe('production');
});

it('accepts quotes and spaces around the separator', function () {
    expect(resolveAppEnv("APP_ENV = \"production\"\n"))->toBe('production')
        ->and(resolveAppEnv("  APP_ENV='production'\n"))->toBe('production');
});

it('strips an inline comment from the value', function () {
    expect(resolveAppEnv("APP_ENV=production # não mexer\n"))->toBe('production');
});

it('ignores a commented-out APP_ENV line', function () {
    expect(resolveAppEnv("#APP_ENV=local\nAPP_ENV=production\n"))->toBe('production');
});

it('takes the first declaration when APP_ENV appears twice', function () {
    // Mesma precedência do Dotenv do Laravel: a primeira linha vence.
    expect(resolveAppEnv("APP_ENV=production\nAPP_ENV=local\n"))->toBe('production');
});

it('falls back to the shell variable when the .env has no APP_ENV', function () {
    expect(resolveAppEnv("APP_NAME=Estoque\n", shellValue: 'production'))
        ->toBe('production');
});

it('falls back to the shell variable when the .env file is missing', function () {
    expect(resolveAppEnv(null, shellValue: 'production'))->toBe('production');
});

it('falls back to local when neither the file nor the shell declares it', function () {
    expect(resolveAppEnv(null))->toBe('local');
});

it('treats an empty APP_ENV in the .env as absent', function () {
    expect(resolveAppEnv("APP_ENV=\n", shellValue: 'production'))->toBe('production');
});
