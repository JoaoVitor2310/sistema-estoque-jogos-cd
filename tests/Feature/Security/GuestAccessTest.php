<?php

/*
|--------------------------------------------------------------------------
| GuestAccessTest — permission system security for unauthenticated visitors
|--------------------------------------------------------------------------
|
| Ensures unauthenticated guests:
|
|   1. Page routes blocked (RequireTeam) → 302 redirect to /login
|   2. Only /login and the trade delivery stay open to guests
|   3. Mutations blocked (CheckPermission) → 403
|   4. Second barrier — se a rota perder o middleware, o filtro do controller e a
|      whitelist do IndexKeysRequest ainda seguram (exercitados com
|      withoutMiddleware, porque a rota deixou de ser alcançável):
|      - Sensitive fields absent (key_code, gamivo_id, supplier_url, etc.)
|      - Allowed fields present
|      - Real key_code does not appear in initial page HTML
|
|   And that authenticated users with can-edit:
|   5. Receive full data (key_code, gamivo_id, etc.)
|   6. Can access all pages protected by RequireTeam
|
*/

use App\Domain\Trades\DeliveryCredential;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\RequireTeam;
use App\Models\AuthorizedUsers;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ── Helpers (inline closures to avoid global function conflict with PublicRoutesTest) ──

/** Seeds a key with known key_code and gamivo_id to validate field filtering. */
function seedGuestKey(): void
{
    DB::table('suppliers')->insertOrIgnore([
        'id' => 1,
        'url' => 'https://steamcommunity.com/id/seed',
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

    it('blocks GET /sales', function () {
        $this->get('/sales')->assertRedirectToRoute('login');
    });

    it('blocks GET /financial-months', function () {
        $this->get('/financial-months')->assertRedirectToRoute('login');
    });
});

// ── 2. Public routes accessible to guests ────────────────────────────────────

describe('Guest — the trade delivery is public on purpose', function () {

    // A única superfície do sistema aberta a quem não é da equipe. Quem autoriza
    // é o token da entrega, não a sessão do sistema — por isso ela responde 200
    // ao visitante, e o que protege o dado é a página não trazer nada sem token.
    // Ver docs/adr/0008 e docs/agents/security-and-guardrails.md.

    it('serves the delivery page to a guest, with nothing in it', function () {
        $trade = Trade::create(['date' => now()->toDateString()]);
        $trade->forceFill(DeliveryCredential::issue())->save();

        $response = $this->get("/deliveries/{$trade->fresh()->delivery_uuid}");

        $response->assertStatus(200);
        expect($response->viewData('page')['props']['state'])->toBe('locked');
    });

    it('blocks a guest from writing without the delivery token', function () {
        $trade = Trade::create(['date' => now()->toDateString()]);
        $trade->forceFill(DeliveryCredential::issue())->save();

        $this->patchJson("/deliveries/{$trade->fresh()->delivery_uuid}", ['tf2_qty' => '10'])
            ->assertStatus(403);
    });

    it('404s a delivery uuid that does not exist', function () {
        $this->get('/deliveries/'.Str::uuid())->assertStatus(404);
    });
});

describe('Guest — the catalogue is closed too', function () {

    // Fechado em 2026-08-19: `/keys` e `/bundles` eram as duas páginas abertas a
    // visitante. A leitura de keys já saía filtrada, mas custo e margem por jogo
    // ficavam à vista de qualquer um — inclusive dos suppliers, que passaram a
    // conhecer o domínio pela página de entrega. A página pública volta um dia
    // como portfólio próprio, não como a aba interna aberta.

    it('blocks GET /keys', function () {
        $this->get('/keys')->assertRedirect('/login');
    });

    it('blocks GET /bundles', function () {
        $this->get('/bundles')->assertRedirect('/login');
    });

    it('blocks GET /keys/paginated', function () {
        $this->getJson('/keys/paginated')->assertStatus(403);
    });

    it('blocks POST /keys/search', function () {
        $this->postJson('/keys/search')->assertStatus(403);
    });

    it('sends the fallback route to the login, not to the tab', function () {
        // O `Route::fallback` global manda toda URL desconhecida para `/keys`,
        // que agora rebate no login em vez de mostrar o catálogo.
        $this->get('/nao-existe')->assertRedirect('/keys');
    });
});

describe('Guest — what stays open', function () {

    it('allows GET /login', function () {
        $this->get('/login')->assertStatus(200);
    });
});

// ── 3. Mutations blocked for guests ──────────────────────────────────────────

describe('Guest — mutations return 403', function () {

    it('blocks DELETE /keys/1', function () {
        $this->deleteJson('/keys/1')->assertStatus(403);
    });

    it('blocks DELETE /keys (bulk)', function () {
        $this->deleteJson('/keys', ['games' => []])->assertStatus(403);
    });

    it('blocks GET /trades', function () {
        $this->get('/trades')->assertStatus(403);
    });

    it('blocks POST /trades', function () {
        $this->postJson('/trades', [])->assertStatus(403);
    });

    it('blocks GET /suppliers', function () {
        $this->get('/suppliers')->assertStatus(403);
    });

    it('blocks POST /suppliers', function () {
        $this->postJson('/suppliers', [])->assertStatus(403);
    });

    it('blocks POST /financial-months', function () {
        $this->postJson('/financial-months', [])->assertStatus(403);
    });

    it('blocks GET /financial-months/{month}', function () {
        // Leitura, e mesmo assim atrás do gate: é o extrato do caixa.
        $this->getJson('/financial-months/1')->assertStatus(403);
    });

    it('blocks POST /financial-months/movements', function () {
        $this->postJson('/financial-months/movements', [])->assertStatus(403);
    });

    it('blocks POST /financial-months/close', function () {
        $this->postJson('/financial-months/close', [])->assertStatus(403);
    });

    it('blocks POST /financial-months/transfers', function () {
        $this->postJson('/financial-months/transfers', [])->assertStatus(403);
    });

    it('blocks POST /financial-months/tf2-allocations', function () {
        $this->postJson('/financial-months/tf2-allocations', [])->assertStatus(403);
    });

    it('blocks POST /financial-months/partner-distributions', function () {
        $this->postJson('/financial-months/partner-distributions', [])->assertStatus(403);
    });
});

// Rotas com route model binding: a autorização roda ANTES do binding
// (prioridade declarada em bootstrap/app.php), então o visitante leva 403
// exista ou não o registro.
describe('Guest — key mutations with model binding return 403', function () {

    beforeEach(fn () => seedGuestKey());

    it('blocks PUT /keys/1', function () {
        $this->putJson('/keys/1', [])->assertStatus(403);
    });

    it('blocks DELETE /keys/1', function () {
        $this->deleteJson('/keys/1')->assertStatus(403);
    });
});

describe('Guest — model binding does not leak whether a record exists', function () {

    // Se a autorização rodasse depois do binding, o id inexistente devolveria
    // 404 e o existente 403: variando o id da URL, um visitante enumeraria o
    // que há no banco sem nunca passar pelo gate. Os dois casos têm que
    // responder igual.
    it('answers 403 for both an existing and a missing key', function () {
        seedGuestKey();

        expect($this->deleteJson('/keys/1')->status())
            ->toBe($this->deleteJson('/keys/999999')->status())
            ->toBe(403);
    });

    it('answers 403 for both an existing and a missing game', function () {
        DB::table('games')->insert([
            'id' => 1,
            'name' => 'Portal 2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect($this->deleteJson('/games/1')->status())
            ->toBe($this->deleteJson('/games/999999')->status())
            ->toBe(403);
    });

    it('answers 403 for both an existing and a missing supplier', function () {
        DB::table('suppliers')->insert([
            'id' => 1,
            'url' => 'https://steamcommunity.com/id/test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect($this->deleteJson('/suppliers/1')->status())
            ->toBe($this->deleteJson('/suppliers/999999')->status())
            ->toBe(403);
    });
});

describe('Guest — supplier mutations with model binding return 403', function () {

    beforeEach(function () {
        DB::table('suppliers')->insertOrIgnore([
            'id' => 1,
            'url' => 'https://steamcommunity.com/id/test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    it('blocks PUT /suppliers/1', function () {
        $this->putJson('/suppliers/1', [])->assertStatus(403);
    });

    it('blocks DELETE /suppliers/1', function () {
        $this->deleteJson('/suppliers/1')->assertStatus(403);
    });

    it('blocks POST /suppliers/execute/1', function () {
        $this->postJson('/suppliers/execute/1')->assertStatus(403);
    });
});

describe('Guest — financial-month reopen with model binding returns 403', function () {

    beforeEach(function () {
        DB::table('financial_months')->insert([
            'id' => 1,
            'year' => 2026,
            'month' => 7,
            'status' => 'closed',
            'reinvestment_percent' => 0.20,
            'emergency_percent' => 0.10,
            'partner_one_share' => 0.50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    it('blocks POST /financial-months/1/reopen', function () {
        $this->postJson('/financial-months/1/reopen')->assertStatus(403);
    });

    it('blocks DELETE /financial-months/movements/1', function () {
        DB::table('financial_movements')->insert([
            'id' => 1,
            'financial_month_id' => 1,
            'account_type' => 'principal',
            'direction' => 'credit',
            'category' => 'income',
            'amount' => 100.00,
            'occurred_at' => now()->toDateString(),
            'is_generated' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson('/financial-months/movements/1')->assertStatus(403);

        expect(DB::table('financial_movements')->count())->toBe(1);
    });
});

// ── 4. Segunda barreira: o filtro, sem o middleware ──────────────────────────
//
// A rota deixou de responder a visitante, então o caminho abaixo não é mais
// alcançável pela URL — e é de propósito que o filtro continue existindo. Ele é
// a segunda barreira, do mesmo jeito que a guarda de domínio em
// `MarkTradeDeliveredUseCase` é redundante com o middleware da entrega: se um dia
// alguém tirar o `CheckPermission` da rota, o `key_code` continua não saindo.
// `withoutMiddleware` é o que permite testar exatamente esse cenário.

describe('Guest — sensitive fields absent from /keys/paginated', function () {

    beforeEach(function () {
        test()->withoutMiddleware([CheckPermission::class, RequireTeam::class]);
        seedGuestKey();
    });

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

    beforeEach(function () {
        test()->withoutMiddleware([CheckPermission::class, RequireTeam::class]);
        seedGuestKey();
    });

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

// ── 4b. Visitante não pode FILTRAR por campo que não enxerga ─────────────────
//
// Mascarar só a saída deixava um oráculo cego: a linha ficava escondida, mas o
// total de resultados ainda respondia "existe key com esse key_code?". Repetir
// a pergunta com prefixos crescentes enumera o key_code — o próprio produto
// vendido — além de supplier_url e notes. A whitelist de filtros precisa ser
// escopada pelo can-edit, não só o corpo da resposta.

describe('Guest — forbidden filters on /keys/search return 403', function () {

    beforeEach(function () {
        test()->withoutMiddleware([CheckPermission::class, RequireTeam::class]);
        seedGuestKey();
    });

    it('blocks filtering by key_code', function () {
        $this->postJson('/keys/search', ['key_code' => 'AAAAA'])->assertStatus(403);
    });

    it('blocks filtering by supplier_url', function () {
        $this->postJson('/keys/search', ['supplier_url' => 'steamcommunity'])->assertStatus(403);
    });

    it('blocks filtering by notes', function () {
        $this->postJson('/keys/search', ['notes' => 'x'])->assertStatus(403);
    });

    it('blocks filtering by gamivo_id', function () {
        $this->postJson('/keys/search', ['gamivo_id' => 'GV-99999'])->assertStatus(403);
    });

    it('blocks filtering by total_paid', function () {
        $this->postJson('/keys/search', ['total_paid' => '10'])->assertStatus(403);
    });

    it('blocks filtering by listed_at_filled, which guests do not receive', function () {
        $this->postJson('/keys/search', ['listed_at_filled' => 'filled'])->assertStatus(403);
    });

    it('closes the oracle: no key_code probe changes the result count', function () {
        // Um acerto (AAAAA-BBBBB-CCCCC existe) e um erro devolvem exatamente a
        // mesma resposta — é isso que mata a enumeração por prefixo.
        $hit = $this->postJson('/keys/search', ['key_code' => 'AAAAA']);
        $miss = $this->postJson('/keys/search', ['key_code' => 'ZZZZZ']);

        expect($hit->status())->toBe($miss->status())->toBe(403);
    });
});

describe('Guest — allowed filters on /keys/search still work', function () {

    beforeEach(function () {
        test()->withoutMiddleware([CheckPermission::class, RequireTeam::class]);
        seedGuestKey();
    });

    it('allows filtering by game_name', function () {
        $response = $this->postJson('/keys/search', ['game_name' => 'Test'])->assertStatus(200);

        expect($response->json('data.totalGames'))->toBe(1);
    });

    it('allows filtering by region and acquired_at range', function () {
        $this->postJson('/keys/search', [
            'region' => 'EU',
            'acquired_at_from' => '2020-01-01',
        ])->assertStatus(200);
    });

    it('accepts the empty payload the Keys.vue form sends', function () {
        // buildSearchPayload() envia todos os campos, inclusive os proibidos,
        // porém vazios. Vazio é descartado antes da checagem — senão o guest
        // levaria 403 numa busca legítima.
        $this->postJson('/keys/search', [
            'key_code' => '',
            'supplier_url' => '',
            'notes' => '',
            'gamivo_id' => '',
            'listed_at_filled' => '',
            'claim_type' => [],
            'game_name' => 'Test',
        ])->assertStatus(200);
    });
});

describe('Authorized user (can-edit) — may filter by sensitive fields', function () {

    beforeEach(fn () => seedGuestKey());

    it('allows filtering by key_code', function () {
        $user = User::factory()->create();
        AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

        $response = $this->actingAs($user)
            ->postJson('/keys/search', ['key_code' => 'AAAAA'])
            ->assertStatus(200);

        expect($response->json('data.totalGames'))->toBe(1);
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

// ── 6. Authorized user — access to RequireTeam-protected pages ────────────────

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

    it('accesses GET /sales', function () {
        $user = User::factory()->create();
        AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

        $this->actingAs($user)->get('/sales')->assertStatus(200);
    });

    it('accesses GET /financial-months', function () {
        $user = User::factory()->create();
        AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

        $this->actingAs($user)->get('/financial-months')->assertStatus(200);
    });
});
