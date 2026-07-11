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
*/

use App\Models\AuthorizedUsers;
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
        $trade = Trade::create(['date' => now()->toDateString(), 'games' => []]);

        $this->actingAs(authorizedUser())
            ->putJson("/trades/{$trade->id}", ['tf2Qty' => '12,5'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tf2Qty');
    });

    it('accepts tf2Qty with period as decimal separator', function () {
        $trade = Trade::create(['date' => now()->toDateString(), 'games' => []]);

        $this->actingAs(authorizedUser())
            ->putJson("/trades/{$trade->id}", ['tf2Qty' => '12.5'])
            ->assertStatus(200);

        expect($trade->fresh()->tf2_qty)->toBe('12.50');
    });

    it('persists null tf2_qty when not provided', function () {
        $trade = Trade::create(['date' => now()->toDateString(), 'tf2_qty' => '10.00', 'games' => []]);

        $this->actingAs(authorizedUser())
            ->putJson("/trades/{$trade->id}", [])
            ->assertStatus(200);

        expect($trade->fresh()->tf2_qty)->toBeNull();
    });
});
