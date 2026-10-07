<?php

/*
|--------------------------------------------------------------------------
| UpdateTradeTest — PUT /trades/{trade}
|--------------------------------------------------------------------------
|
| Casos testados:
|
|   Validação de amount:
|     1. amount com vírgula (formato pt-BR)  → 422
|     2. amount com ponto                    → 200, persiste corretamente
|     3. amount ausente                      → 200, persiste null
|
|   Canal de compra (contrato HTTP — as regras de gravação vivem em
|   tests/Feature/UseCases/Trades/UpdateTradeUseCaseTest.php):
|     4. compra direta → responde o bundle_id casado pelo título
|     5. título sem bundle → responde bundle_id nulo, para a aba avisar
|     6. canal fora do enum → 422
|
*/

use App\Domain\Enums\PurchaseChannel;
use App\Domain\Enums\TradeCurrency;
use App\Models\AuthorizedUsers;
use App\Models\Bundle;
use App\Models\Trade;
use App\Models\User;

function authorizedUser(): User
{
    $user = User::factory()->create();
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    return $user;
}

describe('PUT /trades/{trade} — amount validation', function () {

    it('rejects amount with comma as decimal separator', function () {
        $trade = Trade::create(['date' => now()->toDateString()]);

        $this->actingAs(authorizedUser())
            ->putJson("/trades/{$trade->id}", ['amount' => '12,5'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    });

    it('accepts amount with period as decimal separator', function () {
        $trade = Trade::create(['date' => now()->toDateString()]);

        $this->actingAs(authorizedUser())
            ->putJson("/trades/{$trade->id}", ['amount' => '12.5'])
            ->assertStatus(200);

        expect($trade->fresh()->amount)->toBe('12.50');
    });

    it('persists null amount when not provided', function () {
        $trade = Trade::create(['date' => now()->toDateString(), 'amount' => '10.00']);

        $this->actingAs(authorizedUser())
            ->putJson("/trades/{$trade->id}", [])
            ->assertStatus(200);

        expect($trade->fresh()->amount)->toBeNull();
    });
});

describe('PUT /trades/{trade} — currency', function () {

    it('defaults a new trade to TF2', function () {
        $trade = Trade::create(['date' => now()->toDateString()]);

        expect($trade->currency)->toBe(TradeCurrency::Tf2)
            ->and($trade->fresh()->currency)->toBe(TradeCurrency::Tf2);
    });

    it('persists the currency the quantity was agreed in', function () {
        $trade = Trade::create(['date' => now()->toDateString()]);

        $this->actingAs(authorizedUser())
            ->putJson("/trades/{$trade->id}", ['currency' => 'usd', 'amount' => '25.00'])
            ->assertStatus(200);

        expect($trade->fresh()->currency)->toBe(TradeCurrency::Usd);
    });

    it('keeps the stored currency when the payload does not mention it', function () {
        // Aba antiga ou cliente que só edita o título não pode reclassificar o valor acertado.
        $trade = Trade::create(['date' => now()->toDateString(), 'currency' => 'eur', 'amount' => '30.00']);

        $this->actingAs(authorizedUser())
            ->putJson("/trades/{$trade->id}", ['title' => 'Renamed', 'amount' => '30.00'])
            ->assertStatus(200);

        expect($trade->fresh()->currency)->toBe(TradeCurrency::Eur);
    });

    it('rejects a currency outside the enum', function () {
        $trade = Trade::create(['date' => now()->toDateString()]);

        $this->actingAs(authorizedUser())
            ->putJson("/trades/{$trade->id}", ['currency' => 'gbp'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('currency');
    });
});

describe('PUT /trades/{trade} — purchase channel', function () {

    it('responds with the bundle a direct purchase was linked to by its title', function () {
        $bundle = Bundle::create(['name' => 'Humble Choice September']);
        $trade = Trade::create(['date' => now()->toDateString()]);

        $this->actingAs(authorizedUser())
            ->putJson("/trades/{$trade->id}", [
                'purchaseChannel' => 'bundle_store',
                'title' => 'Humble Choice September',
            ])
            ->assertStatus(200)
            ->assertJsonPath('bundle_id', $bundle->id);

        $trade->refresh();
        expect($trade->purchase_channel)->toBe(PurchaseChannel::BundleStore)
            ->and($trade->bundle_id)->toBe($bundle->id);
    });

    it('responds with no bundle when the title names none, so the tab can warn', function () {
        Bundle::create(['name' => 'Humble Choice September']);
        $trade = Trade::create(['date' => now()->toDateString()]);

        $this->actingAs(authorizedUser())
            ->putJson("/trades/{$trade->id}", ['purchaseChannel' => 'bundle_store', 'title' => 'Humble Choice'])
            ->assertStatus(200)
            ->assertJsonPath('bundle_id', null);

        expect($trade->fresh()->bundle_id)->toBeNull();
    });

    it('rejects an unknown channel', function () {
        $trade = Trade::create(['date' => now()->toDateString()]);

        $this->actingAs(authorizedUser())
            ->putJson("/trades/{$trade->id}", ['purchaseChannel' => 'steam_gift'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('purchaseChannel');
    });
});
