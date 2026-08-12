<?php

/*
|--------------------------------------------------------------------------
| POST/DELETE /bundles/{bundle}/games — contrato HTTP
|--------------------------------------------------------------------------
|
| A rota de remoção não tinha FormRequest. `games` ausente virava
| `detach(null)`, e no Eloquent isso desvincula **todos** os jogos do bundle —
| um payload vazio esvaziava o bundle inteiro em silêncio, respondendo 200.
|
*/

use App\Models\AuthorizedUsers;
use App\Models\Bundle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function actingAsBundleEditor(): User
{
    $user = User::factory()->create();
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    return $user;
}

function seedBundleWithGames(int $gameCount): array
{
    $bundle = Bundle::create([
        'name' => 'Humble Choice Agosto',
        'type' => 'choice',
        'release_date' => '2026-08-01',
    ]);

    $gameIds = [];

    for ($i = 0; $i < $gameCount; $i++) {
        $gameIds[] = DB::table('games')->insertGetId([
            'name' => 'Game '.$i,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $bundle->games()->attach($gameIds);

    return [$bundle, $gameIds];
}

beforeEach(fn () => $this->actingAs(actingAsBundleEditor()));

it('removes only the games it was given', function () {
    [$bundle, $gameIds] = seedBundleWithGames(3);

    $this->deleteJson("/bundles/{$bundle->id}/games", ['games' => [$gameIds[0]]])
        ->assertStatus(200);

    expect(DB::table('bundle_games')->count())->toBe(2);
});

it('refuses an empty removal instead of emptying the bundle', function () {
    // Sem FormRequest isto respondia 200 e apagava os três vínculos.
    [$bundle] = seedBundleWithGames(3);

    $this->deleteJson("/bundles/{$bundle->id}/games", [])->assertStatus(422);

    expect(DB::table('bundle_games')->count())->toBe(3);
});

it('refuses a removal with an empty games array', function () {
    [$bundle] = seedBundleWithGames(3);

    $this->deleteJson("/bundles/{$bundle->id}/games", ['games' => []])->assertStatus(422);

    expect(DB::table('bundle_games')->count())->toBe(3);
});

it('refuses a removal naming a game that does not exist', function () {
    [$bundle] = seedBundleWithGames(1);

    $this->deleteJson("/bundles/{$bundle->id}/games", ['games' => [999999]])->assertStatus(422);

    expect(DB::table('bundle_games')->count())->toBe(1);
});

it('adds games to the bundle', function () {
    [$bundle] = seedBundleWithGames(0);
    $gameId = DB::table('games')->insertGetId([
        'name' => 'Portal 2',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson("/bundles/{$bundle->id}/games", ['games' => [$gameId]])
        ->assertStatus(200);

    expect(DB::table('bundle_games')->count())->toBe(1);
});

it('answers 400 when every game sent is already in the bundle', function () {
    [$bundle, $gameIds] = seedBundleWithGames(1);

    $this->postJson("/bundles/{$bundle->id}/games", ['games' => [$gameIds[0]]])
        ->assertStatus(400)
        ->assertJsonPath('message', 'O jogo selecionado já está no bundle');
});

it('answers 404 for a bundle that does not exist', function () {
    $this->postJson('/bundles/999999/games', ['games' => [1]])
        ->assertStatus(404)
        ->assertJsonPath('message', 'Bundle não encontrado');
});

it('blocks a guest', function () {
    [$bundle, $gameIds] = seedBundleWithGames(1);

    auth()->logout();

    $this->deleteJson("/bundles/{$bundle->id}/games", ['games' => [$gameIds[0]]])
        ->assertStatus(403);

    expect(DB::table('bundle_games')->count())->toBe(1);
});
