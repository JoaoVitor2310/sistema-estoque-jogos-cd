<?php

/*
|--------------------------------------------------------------------------
| GamerPowerApiService — tests
|--------------------------------------------------------------------------
|
| Toda requisição é interceptada por Http::fake(). A resposta de sucesso vem
| de tests/Fixtures/gamerpower/giveaways.json, que documenta o formato que a
| página da API não descreve (ver docs/GAMERPOWER.md).
|
*/

use App\Services\External\GamerPowerApiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function gamerPowerFixture(): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/gamerpower/giveaways.json')), true);
}

describe('GamerPowerApiService::activeSteamGames()', function () {

    it('asks only for full Steam games', function () {
        Http::fake(['www.gamerpower.com/*' => Http::response(gamerPowerFixture(), 200)]);

        (new GamerPowerApiService)->activeSteamGames();

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://www.gamerpower.com/api/giveaways?')
            && $request['platform'] === 'steam'
            && $request['type'] === 'game');
    });

    it('translates the API format into our columns', function () {
        Http::fake(['www.gamerpower.com/*' => Http::response(gamerPowerFixture(), 200)]);

        $giveaways = (new GamerPowerApiService)->activeSteamGames();

        expect($giveaways)->toHaveCount(3);

        $dwarven = $giveaways[0];
        expect($dwarven['gamerpower_id'])->toBe(3716)
            ->and($dwarven['title'])->toBe('Dwarven Realms (Steam) Key Giveaway')
            ->and($dwarven['worth'])->toBe(9.99)
            ->and($dwarven['gamerpower_url'])->toBe('https://www.gamerpower.com/dwarven-realms-steam-key-giveaway')
            ->and($dwarven['published_date']->toDateTimeString())->toBe('2026-07-16 13:15:06')
            ->and($dwarven['end_date'])->toBeNull();

        expect($giveaways[1]['end_date']->toDateTimeString())->toBe('2026-10-31 23:59:59')
            ->and($giveaways[2]['worth'])->toBeNull();
    });

    it('returns an empty list when the API answers 201 (no active giveaways)', function () {
        Http::fake(['www.gamerpower.com/*' => Http::response(['status' => 0, 'status_message' => 'No active giveaways available at the moment.'], 201)]);

        expect((new GamerPowerApiService)->activeSteamGames())->toBe([]);
    });

    it('returns null when the API fails', function () {
        Http::fake(['www.gamerpower.com/*' => Http::response('Server error', 500)]);

        expect((new GamerPowerApiService)->activeSteamGames())->toBeNull();
    });

    it('returns null when the API is unreachable', function () {
        Http::fake(['www.gamerpower.com/*' => fn () => throw new ConnectionException('timeout')]);

        expect((new GamerPowerApiService)->activeSteamGames())->toBeNull();
    });

    it('returns null when a 200 does not carry a list', function () {
        Http::fake(['www.gamerpower.com/*' => Http::response(['status' => 0], 200)]);

        expect((new GamerPowerApiService)->activeSteamGames())->toBeNull();
    });
});
