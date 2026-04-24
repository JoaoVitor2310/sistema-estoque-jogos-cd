<?php

/*
|--------------------------------------------------------------------------
| PublicRoutes — characterization tests
|--------------------------------------------------------------------------
|
| Verifica que as rotas anteriormente públicas agora exigem autenticação
| via CheckPermission (Gate 'can-edit').
|
| Um usuário não autenticado deve receber 403 em todas essas rotas.
| Um usuário autenticado e autorizado deve conseguir acessar.
|
*/

use App\Models\AuthorizedUsers;
use App\Models\User;

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeAuthorizedUser(): User
{
    $user = User::factory()->create();
    // authorized_users exige name além de email
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    return $user;
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('Protected routes', function () {

    // ── games routes ──────────────────────────────────────────────────────────

    describe('GET /games/paginated', function () {

        it('returns 403 for unauthenticated requests', function () {
            $this->getJson('/games/paginated')->assertStatus(403);
        });

        it('returns 200 for authorized users', function () {
            $user = makeAuthorizedUser();

            $this->actingAs($user)->getJson('/games/paginated')->assertStatus(200);
        });
    });

    describe('POST /games/search', function () {

        it('returns 403 for unauthenticated requests', function () {
            $this->postJson('/games/search')->assertStatus(403);
        });

        it('returns 200 for authorized users', function () {
            $user = makeAuthorizedUser();

            $this->actingAs($user)->postJson('/games/search', ['search' => ''])->assertStatus(200);
        });
    });

    describe('GET /games/search-popularity', function () {

        it('returns 403 for unauthenticated requests', function () {
            $this->getJson('/games/search-popularity')->assertStatus(403);
        });

        it('returns 200 for authorized users', function () {
            $user = makeAuthorizedUser();

            $this->actingAs($user)->getJson('/games/search-popularity')->assertStatus(200);
        });
    });

    describe('POST /games/update-popularity', function () {

        it('returns 403 for unauthenticated requests', function () {
            $this->postJson('/games/update-popularity')->assertStatus(403);
        });

        it('accepts requests from authorized users', function () {
            $user = makeAuthorizedUser();

            // Envia payload vazio — não queremos testar a lógica, só a camada de auth.
            // O importante é que a resposta NÃO seja 403 (middleware bloqueando).
            $response = $this->actingAs($user)->postJson('/games/update-popularity', []);

            expect($response->status())->not->toBe(403);
        });
    });

    // ── keys routes ───────────────────────────────────────────────

    describe('GET /keys/paginated', function () {

        it('returns 403 for unauthenticated requests', function () {
            $this->getJson('/keys/paginated')->assertStatus(403);
        });

        it('returns 200 for authorized users', function () {
            $user = makeAuthorizedUser();

            $this->actingAs($user)->getJson('/keys/paginated')->assertStatus(200);
        });
    });

    describe('POST /keys/search', function () {

        it('returns 403 for unauthenticated requests', function () {
            $this->postJson('/keys/search')->assertStatus(403);
        });

        it('returns 200 for authorized users', function () {
            $user = makeAuthorizedUser();

            $this->actingAs($user)->postJson('/keys/search', ['search' => ''])->assertStatus(200);
        });
    });
});
