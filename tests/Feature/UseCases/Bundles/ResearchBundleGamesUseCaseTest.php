<?php

/*
|--------------------------------------------------------------------------
| ResearchBundleGamesUseCase — integration
|--------------------------------------------------------------------------
|
| A pesquisa em si acontece no price_researcher; aqui se garante que o disparo
| leva os jogos do bundle, e que as formas de falha viram resultado tratável
| em vez de exceção — inclusive a mais traiçoeira, o 200 em modo demo, que
| parece sucesso e nunca chama o callback.
|
*/

use App\Models\Bundle;
use App\UseCases\Bundles\ResearchBundleGamesUseCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function seedResearchableBundle(array $gameNames): Bundle
{
    $bundle = Bundle::create([
        'name' => 'Humble Perplexing Puzzles Bundle',
        'type' => 'bundle',
        'release_date' => '2026-05-20',
    ]);

    foreach ($gameNames as $name) {
        $gameId = DB::table('games')->insertGetId([
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bundle->games()->attach($gameId);
    }

    return $bundle;
}

beforeEach(fn () => config(['services.price_researcher.internal_secret' => 'segredo-de-teste']));

it('sends the bundle games and title to the price_researcher', function () {
    $bundle = seedResearchableBundle(['Taiji', 'Viewfinder']);

    Http::fake(['*/api/games/research' => Http::response(['success' => true, 'status' => 'queued'], 202)]);

    $result = app(ResearchBundleGamesUseCase::class)->execute($bundle);

    expect($result['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        return $request['title'] === 'Humble Perplexing Puzzles Bundle'
            && $request['gameNames'] === ['Taiji', 'Viewfinder']
            && $request['minPopularity'] === 1
            && $request['checkGamivoOffer'] === false
            && $request['minPrice'] === 0
            && $request['internal_secret'] === 'segredo-de-teste';
    });
});

it('refuses a bundle with no games instead of calling the service', function () {
    $bundle = seedResearchableBundle([]);

    Http::fake();

    $result = app(ResearchBundleGamesUseCase::class)->execute($bundle);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(400);

    Http::assertNothingSent();
});

it('refuses to fire without the internal secret', function () {
    // Sem segredo o serviço responde 200 em modo demo e nada chega ao callback:
    // o disparo pareceria ter dado certo e nunca viraria trade.
    config(['services.price_researcher.internal_secret' => null]);

    $bundle = seedResearchableBundle(['Taiji']);

    Http::fake();

    $result = app(ResearchBundleGamesUseCase::class)->execute($bundle);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(500);

    Http::assertNothingSent();
});

it('treats a demo-mode 200 as a configuration failure', function () {
    $bundle = seedResearchableBundle(['Taiji']);

    Http::fake(['*/api/games/research' => Http::response(['success' => true, 'demo' => true, 'games' => []], 200)]);

    $result = app(ResearchBundleGamesUseCase::class)->execute($bundle);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(500);
});

it('reports the upstream status when the call fails', function () {
    $bundle = seedResearchableBundle(['Taiji']);

    Http::fake(['*/api/games/research' => Http::response(['error' => 'Validation failed'], 400)]);

    $result = app(ResearchBundleGamesUseCase::class)->execute($bundle);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(400)
        ->and($result['data'])->toBe(['error' => 'Validation failed']);
});

it('reports 503 when the price_researcher is unreachable', function () {
    $bundle = seedResearchableBundle(['Taiji']);

    Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

    $result = app(ResearchBundleGamesUseCase::class)->execute($bundle);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(503);
});
