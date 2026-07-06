<?php

/*
|--------------------------------------------------------------------------
| SupplierCrudTest — CRUD /suppliers + executeList
|--------------------------------------------------------------------------
|
| Casos testados:
|
|   POST /suppliers (store):
|     1. Usuário autorizado cria supplier → 201 com campos corretos
|     2. category inválida              → 422
|     3. category 'vip' persiste no banco
|     4. category null persiste como null
|
|   PUT /suppliers/{id} (update):
|     5. Atualiza name e category       → 200 com campos atualizados
|     6. category 'blocked' persiste
|     7. Limpa category para null
|
|   DELETE /suppliers/{id} (destroy):
|     8. Remove supplier do banco       → 200
|
|   POST /suppliers/execute/{id} (executeList):
|     9.  Supplier sem steam_id           → 400
|     10. price-researcher retorna erro  → 400
|     11. price-researcher retorna 200   → 200
|
*/

use App\Models\AuthorizedUsers;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function makeSupplierTestUser(): User
{
    $user = User::factory()->create();
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    return $user;
}

function seedSupplierForCrud(array $overrides = []): int
{
    return DB::table('suppliers')->insertGetId(array_merge([
        'name' => 'Test Supplier',
        'steam_id' => '76561198000000001',
        'url' => 'https://steamcommunity.com/profiles/76561198000000001',
        'is_added' => false,
        'has_traded' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

// ── POST /suppliers ───────────────────────────────────────────────────────────

describe('POST /suppliers — store', function () {

    it('creates supplier and returns 201', function () {
        $user = makeSupplierTestUser();

        $response = $this->actingAs($user)
            ->postJson('/suppliers', [
                'name' => 'New Supplier',
                'steam_id' => '76561198000000099',
                'url' => 'https://steamcommunity.com/profiles/76561198000000099',
                'category' => null,
                'is_added' => false,
            ])
            ->assertStatus(201);

        expect($response->json('name'))->toBe('New Supplier')
            ->and($response->json('steam_id'))->toBe('76561198000000099')
            ->and($response->json('category'))->toBeNull();

        $this->assertDatabaseHas('suppliers', ['steam_id' => '76561198000000099']);
    });

    it('returns 422 when category is invalid', function () {
        $user = makeSupplierTestUser();

        $this->actingAs($user)
            ->postJson('/suppliers', ['category' => 'premium'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category']);
    });

    it('persists category vip correctly', function () {
        $user = makeSupplierTestUser();

        $this->actingAs($user)
            ->postJson('/suppliers', [
                'name' => 'VIP Supplier',
                'category' => 'vip',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('suppliers', ['name' => 'VIP Supplier', 'category' => 'vip']);
    });

    it('persists null category correctly', function () {
        $user = makeSupplierTestUser();

        $this->actingAs($user)
            ->postJson('/suppliers', ['name' => 'Normal Supplier', 'category' => null])
            ->assertStatus(201);

        $this->assertDatabaseHas('suppliers', ['name' => 'Normal Supplier', 'category' => null]);
    });
});

// ── PUT /suppliers/{id} ───────────────────────────────────────────────────────

describe('PUT /suppliers/{id} — update', function () {

    it('updates name and category and returns 200', function () {
        $user = makeSupplierTestUser();
        $id = seedSupplierForCrud(['name' => 'Old Name', 'category' => null]);

        $response = $this->actingAs($user)
            ->putJson("/suppliers/{$id}", ['name' => 'New Name', 'category' => 'vip'])
            ->assertStatus(200);

        expect($response->json('name'))->toBe('New Name')
            ->and($response->json('category'))->toBe('vip');

        $this->assertDatabaseHas('suppliers', ['id' => $id, 'name' => 'New Name', 'category' => 'vip']);
    });

    it('persists category blocked correctly', function () {
        $user = makeSupplierTestUser();
        $id = seedSupplierForCrud();

        $this->actingAs($user)
            ->putJson("/suppliers/{$id}", ['category' => 'blocked'])
            ->assertStatus(200);

        $this->assertDatabaseHas('suppliers', ['id' => $id, 'category' => 'blocked']);
    });

    it('clears category to null', function () {
        $user = makeSupplierTestUser();
        $id = seedSupplierForCrud(['category' => 'vip']);

        $this->actingAs($user)
            ->putJson("/suppliers/{$id}", ['category' => null])
            ->assertStatus(200);

        $this->assertDatabaseHas('suppliers', ['id' => $id, 'category' => null]);
    });
});

// ── DELETE /suppliers/{id} ────────────────────────────────────────────────────

describe('DELETE /suppliers/{id} — destroy', function () {

    it('deletes supplier and returns 200', function () {
        $user = makeSupplierTestUser();
        $id = seedSupplierForCrud();

        $this->actingAs($user)
            ->deleteJson("/suppliers/{$id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('suppliers', ['id' => $id]);
    });
});

// ── POST /suppliers/execute/{id} ──────────────────────────────────────────────

describe('POST /suppliers/execute/{id} — executeList', function () {

    it('returns 400 when supplier has no steam_id', function () {
        $user = makeSupplierTestUser();
        $id = seedSupplierForCrud(['steam_id' => null]);

        $this->actingAs($user)
            ->postJson("/suppliers/execute/{$id}")
            ->assertStatus(400);
    });

    it('returns 400 when price-researcher responds with error', function () {
        Http::fake(['*' => Http::response(['error' => 'service unavailable'], 503)]);

        $user = makeSupplierTestUser();
        $id = seedSupplierForCrud();

        $this->actingAs($user)
            ->postJson("/suppliers/execute/{$id}")
            ->assertStatus(400);
    });

    it('returns 200 when price-researcher responds with success', function () {
        Http::fake(['*' => Http::response(['queued' => true], 200)]);

        $user = makeSupplierTestUser();
        $id = seedSupplierForCrud();

        $this->actingAs($user)
            ->postJson("/suppliers/execute/{$id}")
            ->assertStatus(200);
    });
});
