<?php

/*
|--------------------------------------------------------------------------
| Exclusão em lote — contrato dos 5 destroyArray
|--------------------------------------------------------------------------
|
| Os controllers conferiam registro a registro DENTRO do loop de exclusão: um
| id inválido no meio do lote abortava com erro depois de já ter apagado os
| anteriores. A tela recebia falha sobre um estado que havia mudado em parte, e
| não tinha como saber o quanto.
|
| Agora a existência é validada na fronteira (DeleteManyRequest) e a exclusão é
| um `whereIn` só — atômico por construção, sem transação.
|
| `games` e `keys` são soft-delete (docs/adr/0011), então a contagem sai pelo
| Eloquent: "apagado" aqui significa fora das queries da aplicação, e a linha
| segue no banco. `fees`/`assets`/`authorized_users` continuam hard delete e por
| isso contam por `DB::table` — a diferença entre as duas famílias é o ponto.
|
*/

use App\Models\AuthorizedUsers;
use App\Models\Game;
use App\Models\Key;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function actingAsBulkAdmin(): User
{
    $user = User::factory()->create(['email' => 'admin@carcadeals.test']);
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    config(['app.admin_gate_email' => $user->email]);

    return $user;
}

function seedBulkGames(int $count): array
{
    $ids = [];

    for ($i = 0; $i < $count; $i++) {
        $ids[] = DB::table('games')->insertGetId([
            'name' => 'Game '.$i,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $ids;
}

beforeEach(fn () => $this->actingAs(actingAsBulkAdmin()));

it('deletes every game of the batch', function () {
    $ids = seedBulkGames(3);

    $this->deleteJson('/games', ['games' => array_map(fn ($id) => ['id' => $id], $ids)])
        ->assertStatus(200);

    expect(Game::count())->toBe(0);
});

it('deletes nothing when one id of the batch does not exist', function () {
    // O caso que o loop errava: os dois primeiros já teriam sido apagados
    // quando o terceiro falhasse.
    $ids = seedBulkGames(2);

    $this->deleteJson('/games', [
        'games' => [['id' => $ids[0]], ['id' => $ids[1]], ['id' => 999999]],
    ])->assertStatus(422);

    expect(Game::count())->toBe(2);
});

it('rejects an empty batch', function () {
    $this->deleteJson('/games', ['games' => []])->assertStatus(422);
});

it('rejects a batch without the items key', function () {
    $this->deleteJson('/games', [])->assertStatus(422);
});

it('ignores the extra row fields the table sends along', function () {
    // As telas mandam a linha inteira do DataTable, não só o id.
    $ids = seedBulkGames(1);

    $this->deleteJson('/games', [
        'games' => [['id' => $ids[0], 'name' => 'Game 0', 'popularity' => 10]],
    ])->assertStatus(200);

    expect(Game::count())->toBe(0);
});

it('accepts the payload key each screen actually sends', function () {
    // Cada tela usa uma chave diferente; trocar uma pela outra tem que falhar.
    $ids = seedBulkGames(1);

    $this->deleteJson('/games', ['assets' => [['id' => $ids[0]]]])->assertStatus(422);

    expect(Game::count())->toBe(1);
});

it('deletes keys under the games key', function () {
    DB::table('suppliers')->insert([
        'id' => 1,
        'url' => 'https://steamcommunity.com/id/seed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $id = DB::table('keys')->insertGetId([
        'key_code' => 'AAAAA-BBBBB-CCCCC',
        'supplier_id' => 1,
        'supplier_url' => 'https://steamcommunity.com/id/seed',
        'game_name' => 'Portal 2',
        'identified_platform' => 'Steam',
        'region' => 'EU',
        'market_price' => 10,
        'individual_cost' => 5,
        'min_api' => 7,
        'max_api' => 40,
        'acquired_at' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->deleteJson('/keys', ['games' => [['id' => $id]]])->assertStatus(200);

    expect(Key::count())->toBe(0);
});

it('deletes fees under the taxas key', function () {
    $id = DB::table('fees')->insertGetId([
        'name' => 'gamivoPercentualMenor',
        'preco' => 6,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->deleteJson('/fees', ['taxas' => [['id' => $id]]])->assertStatus(200);

    expect(DB::table('fees')->count())->toBe(0);
});

it('deletes assets under the assets key', function () {
    $id = DB::table('assets')->insertGetId([
        'name' => 'TF2',
        'price_brl' => 10,
        'price_dollar' => 2,
        'price_euro' => 1.8,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->deleteJson('/assets', ['assets' => [['id' => $id]]])->assertStatus(200);

    expect(DB::table('assets')->count())->toBe(0);
});

it('deletes authorized users under the items key', function () {
    $target = AuthorizedUsers::create([
        'name' => 'Alvo',
        'email' => 'alvo@carcadeals.test',
        'status' => true,
    ]);

    $this->deleteJson('/authorize', ['items' => [['id' => $target->id]]])->assertStatus(200);

    expect(AuthorizedUsers::find($target->id))->toBeNull();
});
