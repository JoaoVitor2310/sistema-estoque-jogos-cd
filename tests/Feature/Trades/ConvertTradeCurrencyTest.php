<?php

/*
|--------------------------------------------------------------------------
| POST /trades/{trade}/currency — HTTP
|--------------------------------------------------------------------------
|
| A borda da troca de moeda: permissão, validação e o código de cada recusa. A
| regra da conversão está em ConvertTradeCurrencyUseCaseTest.
|
*/

use App\Domain\Enums\TradeCurrency;
use App\Models\AuthorizedUsers;
use App\Models\Trade;
use App\Models\TradeOffer;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function currencyEditor(): User
{
    $user = User::factory()->create();
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    return $user;
}

function seedCurrencyRouteQuotes(float $dollar): void
{
    Cache::flush();

    DB::table('assets')->updateOrInsert(
        ['name' => 'TF2'],
        ['price_euro' => 1.46, 'price_dollar' => $dollar, 'price_brl' => 0.0, 'created_at' => now(), 'updated_at' => now()],
    );
}

beforeEach(fn () => seedCurrencyRouteQuotes(1.70));

describe('POST /trades/{trade}/currency', function () {

    it('answers the currency and the amount as they were stored', function () {
        $trade = Trade::create(['date' => now()->toDateString(), 'amount' => '10.00']);

        $this->actingAs(currencyEditor())
            ->postJson("/trades/{$trade->id}/currency", ['currency' => 'usd'])
            ->assertStatus(200)
            ->assertExactJson(['currency' => 'usd', 'amount' => '17.00']);
    });

    it('answers 409 with the reason when the trade has an offer', function () {
        $trade = Trade::create(['date' => now()->toDateString(), 'amount' => '10.00']);
        TradeOffer::create([
            'trade_id' => $trade->id, 'round' => 1, 'profit_percent' => 70,
            'currency' => TradeCurrency::Tf2, 'tf2_rate' => 1,
        ]);

        $this->actingAs(currencyEditor())
            ->postJson("/trades/{$trade->id}/currency", ['currency' => 'usd'])
            ->assertStatus(409)
            ->assertExactJson(['reason' => 'trade_has_offer']);
    });

    it('answers 503 without the TF2 price', function () {
        seedCurrencyRouteQuotes(0.0);
        $trade = Trade::create(['date' => now()->toDateString(), 'amount' => '10.00']);

        $this->actingAs(currencyEditor())
            ->postJson("/trades/{$trade->id}/currency", ['currency' => 'usd'])
            ->assertStatus(503);
    });

    it('validates the currency', function (mixed $currency) {
        $trade = Trade::create(['date' => now()->toDateString()]);

        $this->actingAs(currencyEditor())
            ->postJson("/trades/{$trade->id}/currency", ['currency' => $currency])
            ->assertStatus(422)
            ->assertJsonValidationErrors('currency');
    })->with([
        'missing' => [null],
        'outside the enum' => ['gbp'],
    ]);

    it('refuses a user who cannot edit', function () {
        $trade = Trade::create(['date' => now()->toDateString(), 'amount' => '10.00']);

        $this->actingAs(User::factory()->create())
            ->postJson("/trades/{$trade->id}/currency", ['currency' => 'usd'])
            ->assertStatus(403);

        expect($trade->fresh()->currency)->toBe(TradeCurrency::Tf2);
    });
});
