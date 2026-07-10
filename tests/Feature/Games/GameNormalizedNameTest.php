<?php

/*
|--------------------------------------------------------------------------
| GameNormalizedNameTest — normalized_name preenchido em cada write path
|--------------------------------------------------------------------------
|
| Garante que os pontos de escrita HTTP de games (store/update) preenchem
| normalized_name explicitamente, sem depender de Observer/mutator.
|
*/

use App\Domain\Games\GameNameNormalizer;
use App\Models\AuthorizedUsers;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function makeGameTestUser(): User
{
    $user = User::factory()->create();
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    return $user;
}

describe('POST /games — store fills normalized_name', function () {

    it('fills normalized_name from the provided name', function () {
        $user = makeGameTestUser();

        $this->actingAs($user)
            ->postJson('/games', [
                'games' => [
                    ['name' => 'The Witcher III', 'region' => null],
                ],
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('games', [
            'name' => 'The Witcher III',
            'normalized_name' => GameNameNormalizer::normalize('The Witcher III'),
        ]);
    });
});

describe('PUT /games/{id} — update refreshes normalized_name', function () {

    it('updates normalized_name when name changes', function () {
        $user = makeGameTestUser();
        $gameId = DB::table('games')->insertGetId([
            'name' => 'Old Name',
            'normalized_name' => GameNameNormalizer::normalize('Old Name'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->putJson("/games/{$gameId}", ['name' => 'Grand Theft Auto V'])
            ->assertStatus(200);

        $this->assertDatabaseHas('games', [
            'id' => $gameId,
            'name' => 'Grand Theft Auto V',
            'normalized_name' => GameNameNormalizer::normalize('Grand Theft Auto V'),
        ]);
    });
});
