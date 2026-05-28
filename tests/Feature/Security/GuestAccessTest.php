<?php

/*
|--------------------------------------------------------------------------
| GuestAccessTest — permission system security for unauthenticated visitors
|--------------------------------------------------------------------------
|
| Ensures unauthenticated guests:
|
|   1. Page routes blocked (RequireAuth) → 302 redirect to /login
|   2. Public routes allowed → 200
|   3. Mutations blocked (CheckPermission) → 403
|   4. Filtered data on /keys/paginated and /keys/search:
|      - Sensitive fields absent (key_code, gamivo_id, supplier_url, etc.)
|      - Allowed fields present
|      - Real key_code does not appear in initial page HTML
|
|   And that authenticated users with can-edit:
|   5. Receive full data (key_code, gamivo_id, etc.)
|   6. Can access all pages protected by RequireAuth
|
*/

use App\Models\AuthorizedUsers;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// ── Helpers (inline closures to avoid global function conflict with PublicRoutesTest) ──

/** Seeds a key with known key_code and gamivo_id to validate field filtering. */
function seedGuestKey(): void
{
    DB::table('suppliers')->insertOrIgnore([
        'id' => 1,
        'supplier_url' => 'https://steamcommunity.com/id/seed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('keys')->insertOrIgnore([
        'id' => 1,
        'key_code' => 'AAAAA-BBBBB-CCCCC',
        'supplier_id' => 1,
        'supplier_url' => 'https://steamcommunity.com/id/seed',
        'game_name' => 'Test Game',
        'identified_platform' => 'Steam',
        'region' => 'EU',
        'market_price' => 5.00,
        'individual_cost' => 3.00,
        'min_api' => 4.20,
        'max_api' => 24.00,
        'purchase_profit' => 1.45,
        'purchase_profit_percent' => 48.3,
        'gamivo_id' => 'GV-99999',
        'acquired_at' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// Fields guests must NEVER receive in the JSON response
const GUEST_FORBIDDEN = [
    'key_code', 'gamivo_id', 'supplier_url', 'tf2_quantity',
    'sold_price', 'sale_profit', 'sale_profit_percent',
    'simulated_income', 'listed_at', 'notes', 'claim_type',
    'total_paid', 'id',
];

// Fields that MUST be present for guests
const GUEST_ALLOWED = [
    'identified_platform', 'game_name', 'region', 'market_price',
    'individual_cost', 'min_api', 'max_api',
    'purchase_profit', 'purchase_profit_percent', 'acquired_at',
];

// ── 1. Page routes blocked for guests ────────────────────────────────────────

describe('Guest — page routes redirect to /login', function () {

    it('blocks GET /fees', function () {
        $this->get('/fees')->assertRedirectToRoute('login');
    });

    it('blocks GET /assets', function () {
        $this->get('/assets')->assertRedirectToRoute('login');
    });

    it('blocks GET /games', function () {
        $this->get('/games')->assertRedirectToRoute('login');
    });

    it('blocks GET /acesso', function () {
        $this->get('/acesso')->assertRedirectToRoute('login');
    });

    it('blocks GET /financial', function () {
        $this->get('/financial')->assertRedirectToRoute('login');
    });
});

// ── 2. Public routes accessible to guests ────────────────────────────────────

describe('Guest — public routes return 200', function () {

    it('allows GET /keys', function () {
        $this->get('/keys')->assertStatus(200);
    });

    it('allows GET /bundles', function () {
        $this->get('/bundles')->assertStatus(200);
    });

    it('allows GET /login', function () {
        $this->get('/login')->assertStatus(200);
    });

    it('allows GET /keys/paginated', function () {
        $this->getJson('/keys/paginated')->assertStatus(200);
    });

    it('allows POST /keys/search', function () {
        $this->postJson('/keys/search')->assertStatus(200);
    });
});

// ── 3. Mutations blocked for guests ──────────────────────────────────────────

describe('Guest — mutations return 403', function () {

    it('blocks POST /keys', function () {
        $this->postJson('/keys', ['games' => []])->assertStatus(403);
    });

    it('blocks PUT /keys/1', function () {
        $this->putJson('/keys/1', [])->assertStatus(403);
    });

    it('blocks DELETE /keys/1', function () {
        $this->deleteJson('/keys/1')->assertStatus(403);
    });

    it('blocks DELETE /keys (bulk)', function () {
        $this->deleteJson('/keys', ['games' => []])->assertStatus(403);
    });

    it('blocks POST /keys/import', function () {
        $this->postJson('/keys/import', [])->assertStatus(403);
    });

    it('blocks GET /trades', function () {
        $this->get('/trades')->assertStatus(403);
    });

    it('blocks POST /trades', function () {
        $this->postJson('/trades', [])->assertStatus(403);
    });

    it('blocks GET /vips', function () {
        $this->get('/vips')->assertStatus(403);
    });
});

// ── 4. Filtered data for guests ───────────────────────────────────────────────

describe('Guest — sensitive fields absent from /keys/paginated', function () {

    beforeEach(fn () => seedGuestKey());

    it('returns no sensitive fields', function () {
        $items = $this->getJson('/keys/paginated')
            ->assertStatus(200)
            ->json('data.games.data');

        expect($items)->not->toBeEmpty();

        foreach ($items as $item) {
            foreach (GUEST_FORBIDDEN as $field) {
                expect($item)->not->toHaveKey($field);
            }
        }
    });

    it('returns all allowed fields', function () {
        $items = $this->getJson('/keys/paginated')
            ->assertStatus(200)
            ->json('data.games.data');

        expect($items)->not->toBeEmpty();

        foreach (GUEST_ALLOWED as $field) {
            expect($items[0])->toHaveKey($field);
        }
    });

    it('does not expose the real key_code in the initial page HTML', function () {
        $html = $this->get('/keys')->assertStatus(200)->getContent();

        expect($html)->not->toContain('AAAAA-BBBBB-CCCCC');
    });

    it('does not expose gamivo_id in the initial page HTML', function () {
        $html = $this->get('/keys')->assertStatus(200)->getContent();

        expect($html)->not->toContain('GV-99999');
    });
});

describe('Guest — sensitive fields absent from /keys/search', function () {

    beforeEach(fn () => seedGuestKey());

    // String filters use ILIKE (PostgreSQL only — not supported by SQLite test DB).
    // Sending an empty body triggers the query without filters, which is sufficient
    // to validate that field filtering works regardless of the applied filters.

    it('returns no sensitive fields', function () {
        $items = $this->postJson('/keys/search', [])
            ->assertStatus(200)
            ->json('data.games.data');

        expect($items)->not->toBeEmpty();

        foreach ($items as $item) {
            foreach (GUEST_FORBIDDEN as $field) {
                expect($item)->not->toHaveKey($field);
            }
        }
    });

    it('returns all allowed fields', function () {
        $items = $this->postJson('/keys/search', [])
            ->assertStatus(200)
            ->json('data.games.data');

        expect($items)->not->toBeEmpty();

        foreach (GUEST_ALLOWED as $field) {
            expect($items[0])->toHaveKey($field);
        }
    });
});

// ── 5. Authorized user — full data ───────────────────────────────────────────

describe('Authorized user (can-edit) — receives full data', function () {

    beforeEach(fn () => seedGuestKey());

    it('receives key_code from /keys/paginated', function () {
        $user = User::factory()->create();
        AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

        $items = $this->actingAs($user)
            ->getJson('/keys/paginated')
            ->assertStatus(200)
            ->json('data.games.data');

        expect($items[0])->toHaveKey('key_code');
        expect($items[0]['key_code'])->toBe('AAAAA-BBBBB-CCCCC');
    });

    it('receives gamivo_id from /keys/paginated', function () {
        $user = User::factory()->create();
        AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

        $items = $this->actingAs($user)
            ->getJson('/keys/paginated')
            ->assertStatus(200)
            ->json('data.games.data');

        expect($items[0])->toHaveKey('gamivo_id');
        expect($items[0]['gamivo_id'])->toBe('GV-99999');
    });
});

// ── 6. Authorized user — access to RequireAuth-protected pages ────────────────

describe('Authorized user (can-edit) — accesses pages blocked for guests', function () {

    it('accesses GET /fees', function () {
        $user = User::factory()->create();
        AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

        $this->actingAs($user)->get('/fees')->assertStatus(200);
    });

    it('accesses GET /assets', function () {
        $user = User::factory()->create();
        AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

        $this->actingAs($user)->get('/assets')->assertStatus(200);
    });

    it('accesses GET /games', function () {
        $user = User::factory()->create();
        AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

        $this->actingAs($user)->get('/games')->assertStatus(200);
    });

    it('accesses GET /financial', function () {
        $user = User::factory()->create();
        AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

        $this->actingAs($user)->get('/financial')->assertStatus(200);
    });
});
