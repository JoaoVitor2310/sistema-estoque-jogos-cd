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

use App\Domain\Bundles\BundleResearchRequest;

it('builds the research payload with the bundle criteria', function () {
    $payload = BundleResearchRequest::payload(
        'Humble Perplexing Puzzles Bundle',
        ['Taiji', 'Viewfinder'],
        'segredo',
    );

    expect($payload)->toBe([
        'minPopularity' => 1,
        'gameNames' => ['Taiji', 'Viewfinder'],
        'checkGamivoOffer' => false,
        'minPrice' => 0,
        'internal_secret' => 'segredo',
        'title' => 'Humble Perplexing Puzzles Bundle',
    ]);
});

it('always sends minPrice, so the service default does not cut the cheapest games', function () {
    // Omitido, o price_researcher aplica o piso default de €0,50 e descarta
    // em silêncio os jogos mais baratos do bundle.
    $payload = BundleResearchRequest::payload('Bundle X', ['Taiji'], 'segredo');

    expect($payload)->toHaveKey('minPrice')
        ->and($payload['minPrice'])->toBe(0);
});

it('sends only the fields the strict schema accepts', function () {
    $payload = BundleResearchRequest::payload('Bundle X', ['Taiji'], 'segredo');

    expect(array_keys($payload))->toBe([
        'minPopularity',
        'gameNames',
        'checkGamivoOffer',
        'minPrice',
        'internal_secret',
        'title',
    ]);
});

it('reindexes the game names so they serialize as a JSON array', function () {
    // Nomes vêm de um pluck filtrado; buraco no índice viraria objeto no JSON
    // e o schema rejeitaria a lista inteira.
    $payload = BundleResearchRequest::payload('Bundle X', [2 => 'Taiji', 5 => 'Viewfinder'], 'segredo');

    expect($payload['gameNames'])->toBe(['Taiji', 'Viewfinder']);
});
