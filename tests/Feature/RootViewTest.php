<?php

/*
|--------------------------------------------------------------------------
| Layout raiz (app.blade.php) — o nome na aba do navegador
|--------------------------------------------------------------------------
|
| O nome vem do APP_NAME, num lugar só: o servidor o escreve no <title>, e o
| app.js o lê de lá. Antes o app.js usava VITE_APP_NAME, gravado no build do
| CI, que roda sem .env — a aba mostrava "Laravel" em produção.
|
*/

use Illuminate\Support\Facades\Config;

it('writes the app name in the title of every page', function () {
    Config::set('app.name', 'Carca Deals');

    expect($this->get('/login')->content())->toContain('<title inertia>Carca Deals</title>');
});

it('takes the tab name from the title the server wrote, not from a build variable', function () {
    // Variável VITE_ é gravada no JavaScript durante o build, e o build de
    // produção roda no CI sem .env: voltar a usá-la traz o "Laravel" de volta.
    $appJs = file_get_contents(resource_path('js/app.js'));

    expect($appJs)->toContain('const appName = document.title')
        ->and($appJs)->not->toContain('VITE_APP_NAME');
});
