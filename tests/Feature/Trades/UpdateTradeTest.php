<?php

/*
|--------------------------------------------------------------------------
| UpdateTradeTest — PUT /trades/{trade}
|--------------------------------------------------------------------------
|
| Casos testados:
|
|   Validação de tf2Qty:
|     1. tf2Qty com vírgula (formato pt-BR)  → 422
|     2. tf2Qty com ponto                    → 200, persiste corretamente
|     3. tf2Qty ausente                      → 200, persiste null
|
|   Canal de compra (contrato HTTP — as regras de gravação vivem em
|   tests/Feature/UseCases/Trades/UpdateTradeUseCaseTest.php):
|     4. compra direta → responde o bundle_id casado pelo título
|     5. título sem bundle → responde bundle_id nulo, para a aba avisar
|     6. canal fora do enum → 422
|
*/

use App\Domain\Enums\PurchaseChannel;
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

describe('PUT /trades/{trade} — tf2Qty validation', function () {

    it('rejects tf2Qty with comma as decimal separator', function () {
        $trade = Trade::create(['date' => now()->toDateString()]);

        $this->actingAs(authorizedUser())
            ->putJson("/trades/{$trade->id}", ['tf2Qty' => '12,5'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tf2Qty');
    });

    it('accepts tf2Qty with period as decimal separator', function () {
        $trade = Trade::create(['date' => now()->toDateString()]);

        $this->actingAs(authorizedUser())
            ->putJson("/trades/{$trade->id}", ['tf2Qty' => '12.5'])
            ->assertStatus(200);

        expect($trade->fresh()->tf2_qty)->toBe('12.50');
    });

    it('persists null tf2_qty when not provided', function () {
        $trade = Trade::create(['date' => now()->toDateString(), 'tf2_qty' => '10.00']);

        $this->actingAs(authorizedUser())
            ->putJson("/trades/{$trade->id}", [])
            ->assertStatus(200);

        expect($trade->fresh()->tf2_qty)->toBeNull();
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
