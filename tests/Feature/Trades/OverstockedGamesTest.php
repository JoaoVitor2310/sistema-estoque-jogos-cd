<?php

/*
|--------------------------------------------------------------------------
| GET /trades/overstocked-games — feature tests
|--------------------------------------------------------------------------
|
| Contrato HTTP da lista que a aba de Trades abre no botão "Jogos encalhados":
| os jogos encalhados por região e os limites da regra, que o modal usa para
| explicá-la.
|
| A regra está coberta em tests/Unit/Domain/Trades/OverstockPolicyTest.php e o
| encontro dela com o banco em tests/Feature/Services/OverstockServiceTest.php.
|
*/

use App\Domain\Trades\OverstockPolicy;
use App\Models\AuthorizedUsers;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function makeOverstockUser(): User
{
    $user = User::factory()->create();
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    return $user;
}

function seedOverstockedRouteGame(string $gameName, int $stock = OverstockPolicy::MIN_STOCK, ?string $region = null): void
{
    for ($i = 0; $i < $stock; $i++) {
        DB::table('keys')->insert([
            'game_name' => $gameName,
            'region' => $region,
            'key_code' => 'BLOCKED-'.uniqid(),
            'market_price' => 5.00,
            'individual_cost' => 1.00,
            'min_api' => 1.00,
            'max_api' => 10.00,
            'supplier_url' => 'https://steamcommunity.com/id/test',
            'acquired_at' => now()->subDays(OverstockPolicy::MIN_AGE_DAYS + 5)->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

describe('GET /trades/overstocked-games', function () {

    it('returns the overstocked games with the numbers behind the flag', function () {
        seedOverstockedRouteGame('Curse of the Sea Rats', 4, 'ROW');

        $this->actingAs(makeOverstockUser())
            ->getJson('/trades/overstocked-games')
            ->assertStatus(200)
            ->assertJsonPath('games.0.name', 'Curse of the Sea Rats')
            ->assertJsonPath('games.0.region', 'ROW')
            ->assertJsonPath('games.0.stock', 4)
            ->assertJsonPath('games.0.days_in_stock', OverstockPolicy::MIN_AGE_DAYS + 5)
            ->assertJsonPath('games.0.sold_in_window', 0)
            ->assertJsonPath('games.0.coverage_days', null);
    });

    it('returns the rule thresholds so the tab can explain the block', function () {
        $this->actingAs(makeOverstockUser())
            ->getJson('/trades/overstocked-games')
            ->assertStatus(200)
            ->assertJsonPath('rule.min_stock', OverstockPolicy::MIN_STOCK)
            ->assertJsonPath('rule.min_age_days', OverstockPolicy::MIN_AGE_DAYS)
            ->assertJsonPath('rule.sales_window_days', OverstockPolicy::SALES_WINDOW_DAYS)
            ->assertJsonPath('rule.max_coverage_days', OverstockPolicy::MAX_COVERAGE_DAYS);
    });

    it('returns an empty list when nothing is overstocked', function () {
        $this->actingAs(makeOverstockUser())
            ->getJson('/trades/overstocked-games')
            ->assertStatus(200)
            ->assertJsonCount(0, 'games');
    });
});
