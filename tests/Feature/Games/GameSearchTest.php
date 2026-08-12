<?php

/*
|--------------------------------------------------------------------------
| GameSearchTest — contrato de POST /games/search
|--------------------------------------------------------------------------
|
| A whitelist vive em IndexGamesRequest, na fronteira HTTP, e a montagem da
| query em GameRepository::paginate(). A rota exige can-edit, então aqui não há
| o eixo de vazamento do /keys/search — o que se cobre é o outro lado: nome de
| coluna vindo do cliente nunca vira `where`.
|
*/

use App\Models\AuthorizedUsers;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function actingAsGameEditor(): User
{
    $user = User::factory()->create();
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    return $user;
}

function seedSearchableGame(array $attributes = []): int
{
    return DB::table('games')->insertGetId(array_merge([
        'name' => 'Portal 2',
        'normalized_name' => 'portal2',
        'region' => 'EU',
        'gamivo_id' => '123456',
        'steam_id' => '620',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

beforeEach(fn () => $this->actingAs(actingAsGameEditor()));

it('returns every game when no filter is sent', function () {
    seedSearchableGame();
    seedSearchableGame(['name' => 'Half-Life']);

    $response = $this->postJson('/games/search', []);

    $response->assertStatus(200);
    expect($response->json('data.totalGames'))->toBe(2);
});

it('filters by name substring, ignoring case', function () {
    seedSearchableGame();
    seedSearchableGame(['name' => 'Half-Life']);

    $response = $this->postJson('/games/search', ['name' => 'pOrTaL']);

    expect($response->json('data.totalGames'))->toBe(1)
        ->and($response->json('data.games.data.0.name'))->toBe('Portal 2');
});

it('combines filters with AND', function () {
    seedSearchableGame(['region' => 'EU']);
    seedSearchableGame(['name' => 'Portal 2', 'region' => 'ROW']);

    $response = $this->postJson('/games/search', ['name' => 'Portal', 'region' => 'ROW']);

    expect($response->json('data.totalGames'))->toBe(1);
});

it('filters by gamivo_id and steam_id', function () {
    seedSearchableGame();
    seedSearchableGame(['name' => 'Half-Life', 'gamivo_id' => '999', 'steam_id' => '70']);

    expect($this->postJson('/games/search', ['gamivo_id' => '999'])->json('data.totalGames'))->toBe(1);
    expect($this->postJson('/games/search', ['steam_id' => '70'])->json('data.totalGames'))->toBe(1);
});

it('ignores a filter sent empty', function () {
    // Games.vue e Bundles.vue enviam o objeto de busca inteiro a cada consulta,
    // inclusive os campos em branco.
    seedSearchableGame();

    $response = $this->postJson('/games/search', [
        'name' => 'Portal',
        'region' => '',
        'gamivo_id' => '',
        'steam_id' => '',
    ]);

    expect($response->json('data.totalGames'))->toBe(1);
});

it('rejects an unknown column filter instead of blowing up with 500', function () {
    // O filtro saía de `$request->except('page')`: qualquer chave virava um
    // `where`, e um nome que não fosse coluna derrubava a busca.
    seedSearchableGame();

    $this->postJson('/games/search', ['normalized_name' => 'portal2'])->assertStatus(422);
    $this->postJson('/games/search', ['coluna_inexistente' => 'x'])->assertStatus(422);
    $this->postJson('/games/search', ['popularity' => 10])->assertStatus(422);
});

it('accepts limit as a filter without turning it into a where', function () {
    // `limit` no corpo virava `where limit = ...` sobre coluna inexistente.
    seedSearchableGame();
    seedSearchableGame(['name' => 'Half-Life']);

    $response = $this->postJson('/games/search', ['limit' => 1]);

    $response->assertStatus(200);
    expect($response->json('data.games.data'))->toHaveCount(1)
        ->and($response->json('data.totalGames'))->toBe(2);
});

it('rejects a limit above the maximum', function () {
    $this->postJson('/games/search', ['limit' => 100000])->assertStatus(422);
});

it('paginates with the page query parameter', function () {
    seedSearchableGame();
    seedSearchableGame(['name' => 'Half-Life']);

    $response = $this->postJson('/games/search?page=2', ['limit' => 1]);

    expect($response->json('data.pagination.current_page'))->toBe(2)
        ->and($response->json('data.games.data'))->toHaveCount(1);
});

it('blocks a guest', function () {
    auth()->logout();

    $this->postJson('/games/search', ['name' => 'Portal'])->assertStatus(403);
});
