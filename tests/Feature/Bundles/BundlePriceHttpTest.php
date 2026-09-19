<?php

/*
|--------------------------------------------------------------------------
| POST/PUT /bundles — o preço do bundle é euro
|--------------------------------------------------------------------------
|
| A coluna é `price_euro`. O par rota + FormRequest é onde um rename de campo
| falha calado: nome fora do `rules()` não entra no `validated()`, e o bundle
| seria gravado sem preço respondendo 201.
|
| O usuário é montado aqui, sem helper, porque o Pest promove helper de arquivo
| Feature ao namespace global — e já existe um `actingAsBundleEditor` em
| BundleGamesHttpTest.php.
|
*/

use App\Models\AuthorizedUsers;
use App\Models\Bundle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $user = User::factory()->create();
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    $this->actingAs($user);
});

it('persists the euro price sent on creation', function () {
    $this->postJson('/bundles', [
        'name' => 'Humble Choice Setembro',
        'type' => 'choice',
        'release_date' => '2026-09-01',
        'price_euro' => 10.50,
    ])->assertStatus(201);

    expect((float) DB::table('bundles')->value('price_euro'))->toBe(10.50);
});

it('updates the euro price', function () {
    $bundle = Bundle::create([
        'name' => 'Humble Choice Setembro',
        'type' => 'choice',
        'release_date' => '2026-09-01',
        'price_euro' => 10.50,
    ]);

    $this->putJson("/bundles/{$bundle->id}", [
        'name' => $bundle->name,
        'type' => $bundle->type,
        'release_date' => $bundle->release_date,
        'price_euro' => 12.00,
    ])->assertStatus(200);

    expect((float) DB::table('bundles')->value('price_euro'))->toBe(12.00);
});

it('refuses a negative euro price', function () {
    $this->postJson('/bundles', [
        'name' => 'Humble Choice Setembro',
        'type' => 'choice',
        'release_date' => '2026-09-01',
        'price_euro' => -1.00,
    ])->assertStatus(422);

    expect(DB::table('bundles')->count())->toBe(0);
});
