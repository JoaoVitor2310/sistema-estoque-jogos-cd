<?php

/*
|--------------------------------------------------------------------------
| POST /bundles/{bundle}/research — contrato HTTP
|--------------------------------------------------------------------------
|
| O disparo responde 202, e não 200: o price_researcher só enfileira. A trade
| com os jogos do bundle nasce depois, quando o resultado volta pelo callback
| `POST /trades/from-price-researcher` (coberto em StoreListTradeTest).
|
*/

use App\Models\AuthorizedUsers;
use App\Models\Bundle;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function actingAsBundleResearcher(): User
{
    $user = User::factory()->create();
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    return $user;
}

function seedBundleForResearch(int $gameCount): Bundle
{
    $bundle = Bundle::create([
        'name' => 'Humble Perplexing Puzzles Bundle',
        'type' => 'bundle',
        'release_date' => '2026-05-20',
    ]);

    for ($i = 0; $i < $gameCount; $i++) {
        $bundle->games()->attach(DB::table('games')->insertGetId([
            'name' => 'Game '.$i,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    return $bundle;
}

beforeEach(function () {
    $this->actingAs(actingAsBundleResearcher());
    config(['services.price_researcher.internal_secret' => 'segredo-de-teste']);
});

it('answers 202 when the research is queued', function () {
    $bundle = seedBundleForResearch(2);

    Http::fake(['*/api/games/research' => Http::response(['success' => true, 'status' => 'queued'], 202)]);

    $this->postJson("/bundles/{$bundle->id}/research")
        ->assertStatus(202)
        ->assertJsonPath('message', 'Pesquisa dos jogos do bundle enfileirada.');
});

it('answers 400 for a bundle with no games', function () {
    $bundle = seedBundleForResearch(0);

    Http::fake();

    $this->postJson("/bundles/{$bundle->id}/research")->assertStatus(400);
});

it('answers 404 for a bundle that does not exist', function () {
    $this->postJson('/bundles/999999/research')->assertStatus(404);
});

it('blocks a guest', function () {
    $bundle = seedBundleForResearch(1);

    auth()->logout();

    Http::fake();

    $this->postJson("/bundles/{$bundle->id}/research")->assertStatus(403);

    Http::assertNothingSent();
});
