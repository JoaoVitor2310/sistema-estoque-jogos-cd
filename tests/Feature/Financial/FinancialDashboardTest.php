<?php

/*
|--------------------------------------------------------------------------
| FinancialDashboard — feature tests
|--------------------------------------------------------------------------
|
| Cobre o controller HTTP e cada método do FinancialService:
|
|   - Segurança: rota protegida por CheckPermission
|   - getMonthlySales  — contagem, receita, lucro, margem por mês/ano; month=0
|   - getMonthlyPurchases — contagem e total investido; month=0
|   - getTf2Spent — desduplicação por (total_paid, acquired_at)
|   - getStockSummary — snapshot sem filtro de data; listadas/expiração
|   - getSoldGames — ordenação por lucro desc; campos corretos
|   - getMonthlyTrend — últimos 12 meses; independente do filtro
|
*/

use App\Models\AuthorizedUsers;
use App\Models\Key;
use App\Models\User;
use App\Services\FinancialService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedSupplier(): int
{
    return DB::table('suppliers')->insertGetId([
        'supplier_url' => 'https://steamtrades.com/user/test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function makeAuthorizedFinancialUser(): User
{
    $user = User::factory()->create();
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    return $user;
}

/**
 * Insere uma key com sold_at definido (key vendida).
 */
function soldKey(array $overrides = []): Key
{
    $supplierId = $overrides['supplier_id'] ?? seedSupplier();

    return Key::factory()->create(array_merge([
        'supplier_id' => $supplierId,
        'sold_at' => Carbon::create(2025, 6, 15),
        'acquired_at' => Carbon::create(2025, 6, 1),
        'sold_price' => 10.00,
        'sale_profit' => 4.00,
        'sale_profit_percent' => 40.0,
        'individual_cost' => 6.00,
        'tf2_quantity' => 3.0,
        'total_paid' => '6.00',
        'listed_at' => null,
        'expires_at' => null,
    ], $overrides));
}

/**
 * Insere uma key sem sold_at (estoque).
 */
function stockKey(array $overrides = []): Key
{
    $supplierId = $overrides['supplier_id'] ?? seedSupplier();

    return Key::factory()->create(array_merge([
        'supplier_id' => $supplierId,
        'sold_at' => null,
        'acquired_at' => Carbon::create(2025, 5, 1),
        'individual_cost' => 5.00,
        'simulated_income' => 9.00,
        'listed_at' => null,
        'expires_at' => null,
    ], $overrides));
}

// ── Security ──────────────────────────────────────────────────────────────────

describe('GET /financial', function () {

    it('redirects unauthenticated requests to login (RequireAuth middleware)', function () {
        $this->get('/financial')->assertRedirectToRoute('login');
    });

    it('returns 200 for authorized users', function () {
        $user = makeAuthorizedFinancialUser();

        $this->actingAs($user)->get('/financial')->assertStatus(200);
    });

    it('passes year and month props to the view', function () {
        $user = makeAuthorizedFinancialUser();

        $this->actingAs($user)
            ->get('/financial?year=2025&month=6')
            ->assertInertia(fn ($page) => $page
                ->component('Financial')
                ->where('year', 2025)
                ->where('month', 6)
            );
    });

    it('defaults to current year and month when no params are given', function () {
        $user = makeAuthorizedFinancialUser();

        $this->actingAs($user)
            ->get('/financial')
            ->assertInertia(fn ($page) => $page
                ->where('year', now()->year)
                ->where('month', now()->month)
            );
    });
});

// ── getMonthlySales ───────────────────────────────────────────────────────────

describe('FinancialService::getMonthlySales', function () {

    it('counts sold keys and sums revenue/profit for the given month', function () {
        $sid = seedSupplier();
        soldKey(['supplier_id' => $sid, 'sold_at' => Carbon::create(2025, 6, 10), 'sold_price' => 10.00, 'sale_profit' => 4.00, 'sale_profit_percent' => 40.0]);
        soldKey(['supplier_id' => $sid, 'sold_at' => Carbon::create(2025, 6, 20), 'sold_price' => 20.00, 'sale_profit' => 8.00, 'sale_profit_percent' => 40.0]);
        // key de outro mês — não deve entrar
        soldKey(['supplier_id' => $sid, 'sold_at' => Carbon::create(2025, 5, 15), 'sold_price' => 50.00, 'sale_profit' => 20.00, 'sale_profit_percent' => 40.0]);

        $result = app(FinancialService::class)->getDashboard(2025, 6);

        expect($result['monthly_sales']['count'])->toBe(2);
        expect($result['monthly_sales']['gross_revenue'])->toBe(30.00);
        expect($result['monthly_sales']['net_profit'])->toBe(12.00);
        expect($result['monthly_sales']['avg_margin'])->toBe(40.0);
    });

    it('returns zeros when there are no sales in the month', function () {
        $result = app(FinancialService::class)->getDashboard(2025, 6);

        expect($result['monthly_sales']['count'])->toBe(0);
        expect($result['monthly_sales']['gross_revenue'])->toBe(0.0);
        expect($result['monthly_sales']['net_profit'])->toBe(0.0);
    });

    it('aggregates all months when month is 0', function () {
        $sid = seedSupplier();
        soldKey(['supplier_id' => $sid, 'sold_at' => Carbon::create(2025, 3, 1), 'sold_price' => 10.00, 'sale_profit' => 4.00, 'sale_profit_percent' => 40.0]);
        soldKey(['supplier_id' => $sid, 'sold_at' => Carbon::create(2025, 9, 1), 'sold_price' => 20.00, 'sale_profit' => 8.00, 'sale_profit_percent' => 40.0]);
        // outro ano — não deve entrar
        soldKey(['supplier_id' => $sid, 'sold_at' => Carbon::create(2024, 6, 1), 'sold_price' => 100.00, 'sale_profit' => 50.00, 'sale_profit_percent' => 50.0]);

        $result = app(FinancialService::class)->getDashboard(2025, 0);

        expect($result['monthly_sales']['count'])->toBe(2);
        expect($result['monthly_sales']['gross_revenue'])->toBe(30.00);
        expect($result['monthly_sales']['net_profit'])->toBe(12.00);
    });
});

// ── getMonthlyPurchases ───────────────────────────────────────────────────────

describe('FinancialService::getMonthlyPurchases', function () {

    it('counts purchased keys and sums individual_cost for the given month', function () {
        $sid = seedSupplier();
        Key::factory()->create(['supplier_id' => $sid, 'acquired_at' => Carbon::create(2025, 6, 5), 'individual_cost' => 3.00, 'sold_at' => null]);
        Key::factory()->create(['supplier_id' => $sid, 'acquired_at' => Carbon::create(2025, 6, 10), 'individual_cost' => 7.00, 'sold_at' => null]);
        // outro mês
        Key::factory()->create(['supplier_id' => $sid, 'acquired_at' => Carbon::create(2025, 5, 1), 'individual_cost' => 50.00, 'sold_at' => null]);

        $result = app(FinancialService::class)->getDashboard(2025, 6);

        expect($result['monthly_purchases']['count'])->toBe(2);
        expect($result['monthly_purchases']['total_invested'])->toBe(10.00);
    });

    it('aggregates all months when month is 0', function () {
        $sid = seedSupplier();
        Key::factory()->create(['supplier_id' => $sid, 'acquired_at' => Carbon::create(2025, 2, 1), 'individual_cost' => 4.00, 'sold_at' => null]);
        Key::factory()->create(['supplier_id' => $sid, 'acquired_at' => Carbon::create(2025, 11, 1), 'individual_cost' => 6.00, 'sold_at' => null]);
        // outro ano
        Key::factory()->create(['supplier_id' => $sid, 'acquired_at' => Carbon::create(2024, 6, 1), 'individual_cost' => 100.00, 'sold_at' => null]);

        $result = app(FinancialService::class)->getDashboard(2025, 0);

        expect($result['monthly_purchases']['count'])->toBe(2);
        expect($result['monthly_purchases']['total_invested'])->toBe(10.00);
    });
});

// ── getTf2Spent ───────────────────────────────────────────────────────────────

describe('FinancialService::getTf2Spent', function () {

    it('sums tf2_quantity without double-counting the same trade', function () {
        $sid = seedSupplier();
        // Trade A: 3 keys do mesmo lote (mesmo total_paid + acquired_at)
        // Cada key carrega tf2_quantity = 5.5 — mas a trade é única, deve contar 5.5 uma vez
        $trade = ['supplier_id' => $sid, 'acquired_at' => Carbon::create(2025, 6, 1), 'total_paid' => '5.50', 'tf2_quantity' => 5.5, 'sold_at' => null];
        Key::factory()->create($trade);
        Key::factory()->create($trade);
        Key::factory()->create($trade);

        // Trade B diferente no mesmo mês
        Key::factory()->create(['supplier_id' => $sid, 'acquired_at' => Carbon::create(2025, 6, 15), 'total_paid' => '2.00', 'tf2_quantity' => 2.0, 'sold_at' => null]);

        $result = app(FinancialService::class)->getDashboard(2025, 6);

        // 5.5 (trade A, contada 1x) + 2.0 (trade B) = 7.5
        expect($result['tf2_spent'])->toBe(7.5);
    });

    it('excludes trades outside the filtered period', function () {
        $sid = seedSupplier();
        Key::factory()->create(['supplier_id' => $sid, 'acquired_at' => Carbon::create(2025, 6, 1), 'total_paid' => '3.00', 'tf2_quantity' => 3.0, 'sold_at' => null]);
        // outro mês
        Key::factory()->create(['supplier_id' => $sid, 'acquired_at' => Carbon::create(2025, 5, 1), 'total_paid' => '10.00', 'tf2_quantity' => 10.0, 'sold_at' => null]);

        $result = app(FinancialService::class)->getDashboard(2025, 6);

        expect($result['tf2_spent'])->toBe(3.0);
    });

    it('includes all trades in the year when month is 0', function () {
        $sid = seedSupplier();
        Key::factory()->create(['supplier_id' => $sid, 'acquired_at' => Carbon::create(2025, 1, 1), 'total_paid' => '2.00', 'tf2_quantity' => 2.0, 'sold_at' => null]);
        Key::factory()->create(['supplier_id' => $sid, 'acquired_at' => Carbon::create(2025, 12, 1), 'total_paid' => '3.00', 'tf2_quantity' => 3.0, 'sold_at' => null]);
        // outro ano
        Key::factory()->create(['supplier_id' => $sid, 'acquired_at' => Carbon::create(2024, 6, 1), 'total_paid' => '99.00', 'tf2_quantity' => 99.0, 'sold_at' => null]);

        $result = app(FinancialService::class)->getDashboard(2025, 0);

        expect($result['tf2_spent'])->toBe(5.0);
    });
});

// ── getStockSummary ───────────────────────────────────────────────────────────

describe('FinancialService::getStockSummary', function () {

    it('counts only unsold keys regardless of date filter', function () {
        $sid = seedSupplier();
        stockKey(['supplier_id' => $sid, 'individual_cost' => 4.00, 'simulated_income' => 8.00]);
        stockKey(['supplier_id' => $sid, 'individual_cost' => 6.00, 'simulated_income' => 12.00]);
        soldKey(['supplier_id' => $sid]); // não deve entrar

        $result = app(FinancialService::class)->getDashboard(2025, 6);

        expect($result['stock']['total_count'])->toBe(2);
        expect($result['stock']['total_invested'])->toBe(10.00);
        expect($result['stock']['total_simulated'])->toBe(20.00);
    });

    it('distinguishes listed from unlisted keys', function () {
        $sid = seedSupplier();
        stockKey(['supplier_id' => $sid, 'listed_at' => now()]);
        stockKey(['supplier_id' => $sid, 'listed_at' => now()]);
        stockKey(['supplier_id' => $sid, 'listed_at' => null]);

        $result = app(FinancialService::class)->getDashboard(2025, 6);

        expect($result['stock']['listed_count'])->toBe(2);
        expect($result['stock']['unlisted_count'])->toBe(1);
    });

    it('counts keys expiring within 30 days', function () {
        $sid = seedSupplier();
        stockKey(['supplier_id' => $sid, 'expires_at' => now()->addDays(10)]);  // expirando
        stockKey(['supplier_id' => $sid, 'expires_at' => now()->addDays(29)]); // expirando
        stockKey(['supplier_id' => $sid, 'expires_at' => now()->addDays(31)]); // fora da janela
        stockKey(['supplier_id' => $sid, 'expires_at' => null]);               // sem expiração

        $result = app(FinancialService::class)->getDashboard(2025, 6);

        expect($result['stock']['expiring_count'])->toBe(2);
    });

    it('is a snapshot — not affected by the year/month filter', function () {
        $sid = seedSupplier();
        stockKey(['supplier_id' => $sid]);

        $resultJune = app(FinancialService::class)->getDashboard(2025, 6);
        $resultDec = app(FinancialService::class)->getDashboard(2025, 12);

        expect($resultJune['stock']['total_count'])->toBe($resultDec['stock']['total_count']);
    });
});

// ── getSoldGames ──────────────────────────────────────────────────────────────

describe('FinancialService::getSoldGames', function () {

    it('returns sold games ordered by profit descending', function () {
        $sid = seedSupplier();
        soldKey(['supplier_id' => $sid, 'sold_at' => Carbon::create(2025, 6, 1), 'game_name' => 'Low Profit Game', 'sale_profit' => 1.00]);
        soldKey(['supplier_id' => $sid, 'sold_at' => Carbon::create(2025, 6, 2), 'game_name' => 'High Profit Game', 'sale_profit' => 9.00]);

        $result = app(FinancialService::class)->getDashboard(2025, 6);

        expect($result['sold_games'][0]['game_name'])->toBe('High Profit Game');
        expect($result['sold_games'][1]['game_name'])->toBe('Low Profit Game');
    });

    it('returns the expected fields for each game', function () {
        $sid = seedSupplier();
        soldKey(['supplier_id' => $sid, 'sold_at' => Carbon::create(2025, 6, 15), 'game_name' => 'Test Game', 'region' => 'EU', 'sold_price' => 10.00, 'sale_profit' => 4.00, 'sale_profit_percent' => 40.0]);

        $result = app(FinancialService::class)->getDashboard(2025, 6);

        expect($result['sold_games'])->toHaveCount(1);
        expect($result['sold_games'][0])->toHaveKeys(['game_name', 'region', 'sold_price', 'sale_profit', 'sale_profit_percent', 'sold_at']);
        expect($result['sold_games'][0]['game_name'])->toBe('Test Game');
        expect($result['sold_games'][0]['region'])->toBe('EU');
        expect($result['sold_games'][0]['sold_price'])->toBe(10.00);
    });

    it('excludes games sold in other months', function () {
        $sid = seedSupplier();
        soldKey(['supplier_id' => $sid, 'sold_at' => Carbon::create(2025, 6, 1), 'game_name' => 'June Game']);
        soldKey(['supplier_id' => $sid, 'sold_at' => Carbon::create(2025, 5, 1), 'game_name' => 'May Game']);

        $result = app(FinancialService::class)->getDashboard(2025, 6);

        expect($result['sold_games'])->toHaveCount(1);
        expect($result['sold_games'][0]['game_name'])->toBe('June Game');
    });

    it('returns empty array when there are no sales', function () {
        $result = app(FinancialService::class)->getDashboard(2025, 6);

        expect($result['sold_games'])->toBeArray()->toBeEmpty();
    });
});

// ── getMonthlyTrend ───────────────────────────────────────────────────────────

describe('FinancialService::getMonthlyTrend', function () {

    it('returns entries with correct structure', function () {
        $sid = seedSupplier();
        soldKey(['supplier_id' => $sid, 'sold_at' => now()->subMonths(2)->startOfMonth(), 'sold_price' => 10.00, 'sale_profit' => 4.00]);

        $result = app(FinancialService::class)->getDashboard(2025, 6);

        expect($result['trend'])->not->toBeEmpty();
        expect($result['trend'][0])->toHaveKeys(['month', 'count', 'gross_revenue', 'net_profit']);
    });

    it('aggregates multiple sales in the same month', function () {
        $sid = seedSupplier();
        $month = now()->subMonths(1)->startOfMonth();
        soldKey(['supplier_id' => $sid, 'sold_at' => $month, 'sold_price' => 10.00, 'sale_profit' => 4.00]);
        soldKey(['supplier_id' => $sid, 'sold_at' => $month->copy()->addDays(5), 'sold_price' => 20.00, 'sale_profit' => 8.00]);

        $result = app(FinancialService::class)->getDashboard(2025, 6);

        $monthKey = $month->format('Y-m');
        $entry = collect($result['trend'])->firstWhere('month', $monthKey);

        expect($entry['count'])->toBe(2);
        expect($entry['gross_revenue'])->toBe(30.00);
        expect($entry['net_profit'])->toBe(12.00);
    });

    it('excludes sales older than 12 months', function () {
        $sid = seedSupplier();
        soldKey(['supplier_id' => $sid, 'sold_at' => now()->subMonths(13)]);

        $result = app(FinancialService::class)->getDashboard(2025, 6);

        $oldMonth = now()->subMonths(13)->format('Y-m');
        $entry = collect($result['trend'])->firstWhere('month', $oldMonth);

        expect($entry)->toBeNull();
    });

    it('is independent of the month filter', function () {
        $sid = seedSupplier();
        soldKey(['supplier_id' => $sid, 'sold_at' => now()->subMonths(2)]);

        $resultJune = app(FinancialService::class)->getDashboard(2025, 6);
        $resultDec = app(FinancialService::class)->getDashboard(2025, 12);

        expect($resultJune['trend'])->toEqual($resultDec['trend']);
    });
});
