<?php

/*
|--------------------------------------------------------------------------
| BundleResearchRequest — unit
|--------------------------------------------------------------------------
|
| O schema do price_researcher é estrito: chave desconhecida no corpo derruba
| a request com 400. Daí o teste de forma exata do payload, e não só do
| conteúdo de cada campo.
|
*/

use App\Services\External\BundleResearchRequest;

describe('payload()', function () {

    it('builds the research payload with the bundle-research preset', function () {
        $payload = BundleResearchRequest::payload(
            'Humble Perplexing Puzzles Bundle',
            ['Taiji', 'Viewfinder'],
            'segredo',
        );

        expect($payload)->toBe([
            'preset' => 'bundle-research',
            'gameNames' => ['Taiji', 'Viewfinder'],
            'internal_secret' => 'segredo',
            'title' => 'Humble Perplexing Puzzles Bundle',
        ]);
    });

    it('reindexes the game names so they serialize as a JSON array', function () {
        // Nomes vêm de um pluck filtrado; buraco no índice viraria objeto no JSON
        // e o schema rejeitaria a lista inteira.
        $payload = BundleResearchRequest::payload('Bundle X', [2 => 'Taiji', 5 => 'Viewfinder'], 'segredo');

        expect($payload['gameNames'])->toBe(['Taiji', 'Viewfinder']);
    });
});

describe('launchPricesPayload()', function () {

    it('builds the launch prices payload with the bundle-sync preset and synchronous delivery', function () {
        $payload = BundleResearchRequest::launchPricesPayload([3 => 'Taiji', 7 => 'Viewfinder'], 'segredo');

        expect($payload)->toBe([
            'preset' => 'bundle-sync',
            'delivery' => 'respond',
            'gameNames' => ['Taiji', 'Viewfinder'],
            'internal_secret' => 'segredo',
        ]);
    });
});

it('never sends loose criteria that would override the preset', function () {
    // Critério avulso sobrescreve o preset campo a campo: mudar a regra no
    // price_researcher deixaria de ter efeito sem ninguém perceber.
    $criteria = ['minPopularity', 'minPrice', 'checkGamivoOffer'];

    expect(BundleResearchRequest::payload('Bundle X', ['Taiji'], 'segredo'))->not->toHaveKeys($criteria)
        ->and(BundleResearchRequest::launchPricesPayload(['Taiji'], 'segredo'))->not->toHaveKeys($criteria);
});
