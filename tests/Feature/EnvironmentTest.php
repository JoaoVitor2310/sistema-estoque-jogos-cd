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

/*
|--------------------------------------------------------------------------
| `env()` fora de config/ — guarda
|--------------------------------------------------------------------------
|
| Guarda do incidente de 2026-09-09: `CurrencyConversionService` lia a chave da
| AwesomeAPI com `env()` em runtime. O deploy roda `php artisan config:cache`
| (.github/workflows/deploy.yml) e, a partir daí, `env()` devolve null — o
| `.env` deixa de ser lido. Local, sem cache, funcionava; em produção a chamada
| ia sem chave, caía no tier público limitado por IP e voltava 429. O sintoma
| era a aba Recursos gravar o preço digitado sem converter os outros dois.
|
| A leitura de `.env` mora em `config/`, e o resto do código lê `config()`.
|
*/

it('never reads env() at runtime outside config/', function () {
    $offenders = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // Pelos tokens, e não por regex: `env(` aparece dentro de comentário e
        // de string, e um grep acusaria os dois.
        $tokens = token_get_all((string) file_get_contents($file->getPathname()));

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'env') {
                continue;
            }

            // Chamada, não `->env` nem `Algo::env`.
            $previous = $tokens[$index - 1] ?? null;
            $isMemberAccess = is_array($previous)
                && in_array($previous[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);

            if (! $isMemberAccess) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).':'.$token[2];
            }
        }
    }

    expect($offenders)->toBe([]);
});
