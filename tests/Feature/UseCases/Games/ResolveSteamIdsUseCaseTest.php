<?php

/*
|--------------------------------------------------------------------------
| ResolveSteamIdsUseCase — integration
|--------------------------------------------------------------------------
|
| A regra central é a distinção entre "nunca procurado" e "procurado sem
| sucesso": sem ela o scheduler reprocessaria para sempre os jogos que o
| SteamCharts não conhece.
|
*/

use App\Mail\SteamIdSearchFailedMail;
use App\UseCases\Games\ResolveSteamIdsUseCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

function seedUnsearchedGame(string $name, ?string $steamId = null, mixed $searchedAt = null): int
{
    return DB::table('games')->insertGetId([
        'name' => $name,
        'steam_id' => $steamId,
        'steamcharts_searched_at' => $searchedAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(fn () => Mail::fake());

it('queries only games with steam_id AND steamcharts_searched_at both null', function () {
    seedUnsearchedGame('Unsearched Game');
    seedUnsearchedGame('Already Searched', null, now());
    seedUnsearchedGame('Has Steam Id', '123456');

    Http::fake(['*/api/games/search-id-steam' => Http::response([
        'success' => true,
        'data' => ['games' => []],
    ], 200)]);

    app(ResolveSteamIdsUseCase::class)->execute();

    Http::assertSentCount(1);
    Http::assertSent(function ($request) {
        $names = array_column($request->data()['games'], 'name');

        return in_array('Unsearched Game', $names)
            && ! in_array('Already Searched', $names)
            && ! in_array('Has Steam Id', $names);
    });
});

it('marks all sent games with steamcharts_searched_at after a successful response', function () {
    seedUnsearchedGame('Game A');
    seedUnsearchedGame('Game B');

    Http::fake(['*/api/games/search-id-steam' => Http::response([
        'success' => true,
        'data' => ['games' => []],
    ], 200)]);

    app(ResolveSteamIdsUseCase::class)->execute();

    // Mesmo sem encontrar nada, ficam marcados — é o que impede o reprocessamento eterno.
    expect(DB::table('games')->whereNull('steamcharts_searched_at')->count())->toBe(0);
});

it('sets steam_id on found games and reports how many were resolved', function () {
    $gameId = seedUnsearchedGame('Found Game');

    Http::fake(['*/api/games/search-id-steam' => Http::response([
        'success' => true,
        'data' => ['games' => [['id' => $gameId, 'name' => 'Found Game', 'id_steam' => '999888']]],
    ], 200)]);

    $resolved = app(ResolveSteamIdsUseCase::class)->execute();

    $game = DB::table('games')->where('id', $gameId)->first();

    expect($resolved)->toBe(1)
        ->and($game->steam_id)->toBe('999888')
        ->and($game->steamcharts_searched_at)->not->toBeNull();
});

it('does NOT mark games as searched when the HTTP call fails', function () {
    seedUnsearchedGame('Pending Game');

    Http::fake(['*/api/games/search-id-steam' => Http::response(['success' => false], 500)]);

    expect(app(ResolveSteamIdsUseCase::class)->execute())->toBe(0);

    // Falha HTTP não diz nada sobre o jogo existir no SteamCharts, então ele
    // continua elegível para a próxima rodada.
    expect(DB::table('games')->where('name', 'Pending Game')->value('steamcharts_searched_at'))->toBeNull();
});

it('alerts by email when the price_researcher call fails', function () {
    seedUnsearchedGame('Pending Game');

    Http::fake(['*/api/games/search-id-steam' => Http::response(['success' => false], 503)]);

    app(ResolveSteamIdsUseCase::class)->execute();

    Mail::assertSent(SteamIdSearchFailedMail::class, fn ($mail) => $mail->summary === 'HTTP 503'
        && $mail->hasTo(config('app.admin_email')));
});

it('alerts by email when the price_researcher is unreachable', function () {
    seedUnsearchedGame('Pending Game');

    // Serviço fora do ar não devolve status — o cliente lança antes de existir
    // resposta. É o modo de falha mais provável, e era o único que passava batido:
    // a exceção subia e o alerta nunca saía.
    Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

    expect(app(ResolveSteamIdsUseCase::class)->execute())->toBe(0);

    expect(DB::table('games')->where('name', 'Pending Game')->value('steamcharts_searched_at'))->toBeNull();
    Mail::assertSent(SteamIdSearchFailedMail::class, fn ($mail) => $mail->summary === 'conexão falhou'
        && str_contains($mail->detail, 'Could not resolve host'));
});

it('treats a 200 response carrying success=false as a failure', function () {
    seedUnsearchedGame('Pending Game');

    // O price_researcher responde 200 com success:false quando a busca quebra
    // internamente — marcar os jogos aqui os condenaria a nunca mais serem buscados.
    Http::fake(['*/api/games/search-id-steam' => Http::response(['success' => false], 200)]);

    app(ResolveSteamIdsUseCase::class)->execute();

    expect(DB::table('games')->where('name', 'Pending Game')->value('steamcharts_searched_at'))->toBeNull();
    Mail::assertSent(SteamIdSearchFailedMail::class);
});

it('does not retire games when a successful response carries no data', function () {
    // Resposta ilegível não prova que o jogo falta no SteamCharts. Marcar aqui
    // o aposentaria para sempre, porque ele nunca mais entraria na consulta.
    seedUnsearchedGame('Pending Game');

    Http::fake(['*/api/games/search-id-steam' => Http::response(['success' => true], 200)]);

    expect(app(ResolveSteamIdsUseCase::class)->execute())->toBe(0);

    expect(DB::table('games')->where('name', 'Pending Game')->value('steamcharts_searched_at'))->toBeNull();
    Mail::assertSent(SteamIdSearchFailedMail::class);
});

it('does nothing when there are no unsearched games', function () {
    seedUnsearchedGame('Searched Game', null, now());

    Http::fake();

    expect(app(ResolveSteamIdsUseCase::class)->execute())->toBe(0);

    Http::assertNothingSent();
    Mail::assertNothingSent();
});
