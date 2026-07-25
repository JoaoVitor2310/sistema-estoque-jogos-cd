<?php

/*
|--------------------------------------------------------------------------
| ImportTradeKeysTest — POST /trades/{trade}/import
|--------------------------------------------------------------------------
|
| Casos testados:
|
|   1. gamivo_id enviado é persistido na key criada
|   2. gamivo_id enviado é propagado para a tabela games
|   3. gamivo_id ausente não quebra a importação (campo nullable)
|   4. as keys importadas são vinculadas à trade (trade_id)
|   5. importação sem erros marca a trade como is_imported
|
*/

use App\Models\AuthorizedUsers;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function seedImportFees(): void
{
    DB::table('fees')->insert([
        ['name' => 'gamivo_percent_low', 'preco' => 0.060, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_low', 'preco' => 0.250, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_percent_high', 'preco' => 0.080, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_high', 'preco' => 0.400, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('assets')->insert([
        ['name' => 'TF2', 'price_euro' => 2.0, 'price_dollar' => 2.2, 'price_brl' => 10.0, 'created_at' => now(), 'updated_at' => now()],
    ]);
}

function makeAuthorizedImportUser(): User
{
    $user = User::factory()->create();
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    return $user;
}

function importGamePayload(array $overrides = []): array
{
    return array_merge([
        'game_name' => 'Half-Life',
        'market_price' => 4.50,
        'supplier_url' => 'https://steamcommunity.com/id/exemplo',
        'tf2_quantity' => 1.5,
        'key_code' => 'AAAAA-BBBBB-CCCCC',
        'acquired_at' => now()->toDateString(),
        'region' => null,
    ], $overrides);
}

describe('POST /trades/{trade}/import — gamivo_id', function () {

    beforeEach(fn () => seedImportFees());

    it('persists gamivo_id on the created key', function () {
        $user = makeAuthorizedImportUser();
        $trade = Trade::create(['games' => []]);

        $this->actingAs($user)
            ->postJson(route('trades.import', ['trade' => $trade->id]), [
                'games' => [importGamePayload(['gamivo_id' => '144601'])],
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('keys', [
            'key_code' => 'AAAAA-BBBBB-CCCCC',
            'gamivo_id' => '144601',
        ]);
    });

    it('propagates gamivo_id to the games table', function () {
        $user = makeAuthorizedImportUser();
        $trade = Trade::create(['games' => []]);

        $this->actingAs($user)
            ->postJson(route('trades.import', ['trade' => $trade->id]), [
                'games' => [importGamePayload(['gamivo_id' => '144601', 'game_name' => 'Propagated Game'])],
            ])
            ->assertStatus(201);

        expect(DB::table('games')->where('gamivo_id', '144601')->exists())->toBeTrue();
    });

    it('imports successfully when gamivo_id is not provided', function () {
        $user = makeAuthorizedImportUser();
        $trade = Trade::create(['games' => []]);

        $this->actingAs($user)
            ->postJson(route('trades.import', ['trade' => $trade->id]), [
                'games' => [importGamePayload()],
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('keys', [
            'key_code' => 'AAAAA-BBBBB-CCCCC',
            'gamivo_id' => null,
        ]);
    });

    it('links the imported keys to the trade (trade_id)', function () {
        $user = makeAuthorizedImportUser();
        $trade = Trade::create(['games' => []]);

        $this->actingAs($user)
            ->postJson(route('trades.import', ['trade' => $trade->id]), [
                'games' => [importGamePayload()],
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('keys', [
            'key_code' => 'AAAAA-BBBBB-CCCCC',
            'trade_id' => $trade->id,
        ]);
    });

    it('marks the trade as imported after a successful import', function () {
        $user = makeAuthorizedImportUser();
        $trade = Trade::create(['games' => []]);

        // default do banco (o modelo recém-criado em memória não reflete o default)
        expect($trade->fresh()->is_imported)->toBeFalse();

        $this->actingAs($user)
            ->postJson(route('trades.import', ['trade' => $trade->id]), [
                'games' => [importGamePayload()],
            ])
            ->assertStatus(201);

        expect($trade->fresh()->is_imported)->toBeTrue();
    });
});
