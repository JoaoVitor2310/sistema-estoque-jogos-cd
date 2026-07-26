<?php

/*
|--------------------------------------------------------------------------
| GET /trades — feature tests
|--------------------------------------------------------------------------
|
| Cobre o contrato HTTP:
|   - default: só abertas, ordenação date DESC
|   - view=imported / view=all
|   - filtros date_from / date_to / tf2_min / tf2_max
|   - sort=tf2_qty & dir=asc
|   - sort/dir/view fora da whitelist → 422
|   - paginação: per_page default 20, page=2 traz os próximos
|
| Segurança de guest é coberta em tests/Feature/Security/GuestAccessTest.php
| (bloco "blocks GET /trades" já existente).
|
*/

use App\Models\AuthorizedUsers;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// TradeController::show carrega taxas do Gamivo + preço TF2 para popular
// props auxiliares do card. Sem esses seeds a página lança MarketplaceFee
// erro de "Undefined array key gamivo_percent_low".
beforeEach(function () {
    DB::table('fees')->insert([
        ['name' => 'gamivo_percent_low', 'preco' => 0.060, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_low', 'preco' => 0.250, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_percent_high', 'preco' => 0.080, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_high', 'preco' => 0.400, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('assets')->insert([
        ['name' => 'TF2', 'price_euro' => 2.0, 'price_dollar' => 2.2, 'price_brl' => 10.0, 'created_at' => now(), 'updated_at' => now()],
    ]);
});

function makeAuthorizedIndexUser(): User
{
    $user = User::factory()->create();
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    return $user;
}

function seedIndexTrade(array $attrs = []): Trade
{
    return Trade::create(array_merge(['games' => []], $attrs));
}

describe('GET /trades — default view', function () {

    it('returns only open trades by default (paginator inside data)', function () {
        $open = seedIndexTrade(['date' => '2025-06-01']);
        seedIndexTrade(['date' => '2025-06-02', 'is_imported' => true]);

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Trades')
                ->where('trades.total', 1)
                ->where('trades.data.0.id', $open->id)
                ->where('trades.data.0.is_imported', false)
            );
    });

    it('orders by date DESC with id DESC tiebreaker', function () {
        $older = seedIndexTrade(['date' => '2025-01-01']);
        $newerA = seedIndexTrade(['date' => '2025-03-01']);
        $newerB = seedIndexTrade(['date' => '2025-03-01']);

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades')
            ->assertInertia(fn ($page) => $page
                ->where('trades.data.0.id', $newerB->id)
                ->where('trades.data.1.id', $newerA->id)
                ->where('trades.data.2.id', $older->id)
            );
    });

    it('passes the current filters back to the view', function () {
        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades')
            ->assertInertia(fn ($page) => $page
                ->where('filters.view', 'open')
                ->where('filters.sort', 'date')
                ->where('filters.dir', 'desc')
            );
    });
});

describe('GET /trades — view filter', function () {

    it('returns only imported trades when view=imported', function () {
        seedIndexTrade(['date' => '2025-06-01']);
        $imported = seedIndexTrade(['date' => '2025-06-02', 'is_imported' => true]);

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades?view=imported')
            ->assertInertia(fn ($page) => $page
                ->where('trades.total', 1)
                ->where('trades.data.0.id', $imported->id)
            );
    });

    it('returns both when view=all', function () {
        seedIndexTrade(['date' => '2025-06-01']);
        seedIndexTrade(['date' => '2025-06-02', 'is_imported' => true]);

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades?view=all')
            ->assertInertia(fn ($page) => $page
                ->where('trades.total', 2)
            );
    });
});

describe('GET /trades — date range', function () {

    it('filters by date_from and date_to', function () {
        seedIndexTrade(['date' => '2025-04-01']);
        seedIndexTrade(['date' => '2025-06-15']);
        seedIndexTrade(['date' => '2025-08-01']);

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades?date_from=2025-05-01&date_to=2025-07-01')
            ->assertInertia(fn ($page) => $page
                ->where('trades.total', 1)
            );
    });
});

describe('GET /trades — tf2 range', function () {

    it('filters by tf2_min and tf2_max', function () {
        seedIndexTrade(['date' => '2025-06-01', 'tf2_qty' => 1.0]);
        seedIndexTrade(['date' => '2025-06-02', 'tf2_qty' => 5.0]);
        seedIndexTrade(['date' => '2025-06-03', 'tf2_qty' => 20.0]);

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades?tf2_min=3&tf2_max=10')
            ->assertInertia(fn ($page) => $page
                ->where('trades.total', 1)
            );
    });
});

describe('GET /trades — sort', function () {

    it('sorts by tf2_qty asc when requested', function () {
        seedIndexTrade(['date' => '2025-06-01', 'tf2_qty' => 10.0]);
        seedIndexTrade(['date' => '2025-06-01', 'tf2_qty' => 2.0]);
        seedIndexTrade(['date' => '2025-06-01', 'tf2_qty' => 5.0]);

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades?sort=tf2_qty&dir=asc')
            ->assertInertia(fn ($page) => $page
                ->where('trades.data.0.tf2_qty', '2.00')
                ->where('trades.data.1.tf2_qty', '5.00')
                ->where('trades.data.2.tf2_qty', '10.00')
            );
    });
});

describe('GET /trades — validation', function () {

    it('rejects sort outside the whitelist with 422', function () {
        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades?sort=title')
            ->assertStatus(302); // Laravel redirects HTML requests on validation failure
    });

    it('returns 422 for invalid sort when the request accepts JSON', function () {
        $this->actingAs(makeAuthorizedIndexUser())
            ->getJson('/trades?sort=drop_table')
            ->assertStatus(422);
    });

    it('returns 422 for invalid view', function () {
        $this->actingAs(makeAuthorizedIndexUser())
            ->getJson('/trades?view=weird')
            ->assertStatus(422);
    });

    it('returns 422 for malformed date', function () {
        $this->actingAs(makeAuthorizedIndexUser())
            ->getJson('/trades?date_from=15/06/2025')
            ->assertStatus(422);
    });
});

describe('GET /trades — pagination', function () {

    it('paginates 20 per page by default and page=2 brings the next batch', function () {
        for ($i = 1; $i <= 25; $i++) {
            seedIndexTrade(['date' => '2025-06-01']);
        }

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades')
            ->assertInertia(fn ($page) => $page
                ->where('trades.total', 25)
                ->where('trades.per_page', 20)
                ->where('trades.current_page', 1)
                ->has('trades.data', 20)
            );

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades?page=2')
            ->assertInertia(fn ($page) => $page
                ->where('trades.current_page', 2)
                ->has('trades.data', 5)
            );
    });
});
