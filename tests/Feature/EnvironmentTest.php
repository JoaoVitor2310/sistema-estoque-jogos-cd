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

/*
|--------------------------------------------------------------------------
| PHP endurecido na imagem — CVE-2026-115560
|--------------------------------------------------------------------------
|
| O ini só vale se estiver na imagem: o Dockerfile precisa copiá-lo para o
| conf.d do PHP. Apagar um dos dois reabre a exposição sem nada quebrar.
|
*/

it('ships the php ini that disables session upload progress', function () {
    $ini = file_get_contents(base_path('docker/php/zz-security.ini'));

    expect($ini)->toMatch('/^session\.upload_progress\.enabled\s*=\s*Off\s*$/m')
        ->and(file_get_contents(base_path('Dockerfile')))
        ->toContain('COPY docker/php/zz-security.ini /usr/local/etc/php/conf.d/zz-security.ini');
});

/*
|--------------------------------------------------------------------------
| Porta interna do nginx — só na rede Docker
|--------------------------------------------------------------------------
|
| O bloco `listen 8080` do default.conf serve a aplicação inteira em HTTP
| puro para o price-researcher e o chatbot. O que o fecha à internet é a 8080
| **não** estar publicada no host: sem lista de IPs, porque a rede é
| `external` e não tem faixa fixa. Publicá-la (para depurar, por exemplo)
| abriria esse caminho sem nada quebrar. Ver security-and-guardrails.md.
|
*/

/**
 * As portas de container publicadas no host pelo compose: o lado direito de
 * cada item de `ports:` ("80:80" → 80, "127.0.0.1:170:80" → 80).
 *
 * Parser mínimo de propósito: o projeto não tem YAML como dependência própria.
 *
 * @return list<int>
 */
function publishedContainerPorts(string $compose): array
{
    $ports = [];
    $portsIndent = null;

    foreach (explode("\n", $compose) as $line) {
        if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
            continue;
        }

        $indent = strlen($line) - strlen(ltrim($line));

        if (preg_match('/^\s*ports:\s*$/', $line)) {
            $portsIndent = $indent;

            continue;
        }

        if ($portsIndent !== null && $indent > $portsIndent && preg_match('/^\s*-\s*["\']?([^"\'\s]+)["\']?/', $line, $match)) {
            $parts = explode(':', explode('/', $match[1])[0]);
            $ports[] = (int) end($parts);

            continue;
        }

        $portsIndent = null;
    }

    return $ports;
}

it('reads the container side of each published port', function () {
    $compose = "services:\n  web:\n    ports:\n      - \"80:80\"\n      - '127.0.0.1:170:8080'\n      - 443:443/tcp\n    volumes:\n      - .:/app\n";

    expect(publishedContainerPorts($compose))->toBe([80, 8080, 443]);
});

it('never publishes the internal nginx port to the host', function () {
    expect(file_get_contents(base_path('docker/nginx/default.conf')))->toMatch('/^\s*listen 8080;/m')
        ->and(publishedContainerPorts(file_get_contents(base_path('docker-compose.yml'))))
        ->not->toBeEmpty()
        ->not->toContain(8080);
});

/*
|--------------------------------------------------------------------------
| Entrada de cada server do nginx — rotas de máquina fechadas no público
|--------------------------------------------------------------------------
|
| O InternalNetworkOnly fecha as rotas de máquina quando o nginx diz que a
| requisição entrou pelo domínio público (ESTOQUE_ENTRY = public), e deixa
| passar quando não diz nada. Um `server` que inclua a aplicação sem o
| `set $estoque_entry` manda o valor vazio e fica aberto sem nada quebrar.
|
*/

/**
 * Os blocos `server { ... }` de uma config do nginx, com o conteúdo de cada um.
 *
 * @return list<string>
 */
function nginxServerBlocks(string $config): array
{
    $blocks = [];
    $offset = 0;

    while (preg_match('/^\s*server\s*\{/m', $config, $match, PREG_OFFSET_CAPTURE, $offset)) {
        $start = $match[0][1] + strlen($match[0][0]);
        $depth = 1;
        $position = $start;

        while ($depth > 0 && $position < strlen($config)) {
            $depth += match ($config[$position]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };
            $position++;
        }

        $blocks[] = substr($config, $start, $position - $start - 1);
        $offset = $position;
    }

    return $blocks;
}

it('passes the entry of the request from the app snippet to php', function () {
    expect(file_get_contents(base_path('docker/nginx/snippets/laravel-app.conf')))
        ->toMatch('/^\s*fastcgi_param ESTOQUE_ENTRY \$estoque_entry;/m');
});

it('says where the request came from in every server that serves the app', function (string $file) {
    $servingApp = array_filter(
        nginxServerBlocks(file_get_contents(base_path($file))),
        fn (string $block) => str_contains($block, 'snippets/laravel-app.conf'),
    );

    expect($servingApp)->not->toBeEmpty();

    foreach ($servingApp as $block) {
        expect($block)->toMatch('/^\s*set \$estoque_entry (public|internal);/m');
    }
})->with(['docker/nginx/default.conf', 'docker/nginx/default.dev.conf']);

it('marks the public server as public and the internal port as internal in production', function () {
    $blocks = nginxServerBlocks(file_get_contents(base_path('docker/nginx/default.conf')));

    $entryOf = function (string $listen) use ($blocks): ?string {
        foreach ($blocks as $block) {
            if (preg_match('/^\s*listen '.preg_quote($listen, '/').';/m', $block)
                && preg_match('/^\s*set \$estoque_entry (\w+);/m', $block, $match)) {
                return $match[1];
            }
        }

        return null;
    };

    expect($entryOf('443 ssl'))->toBe('public')
        ->and($entryOf('8080'))->toBe('internal');
});
