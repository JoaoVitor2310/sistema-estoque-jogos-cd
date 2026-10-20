<?php

/*
|--------------------------------------------------------------------------
| GET /giveaways — feature tests
|--------------------------------------------------------------------------
|
| Contrato da página: componente, projeção dos giveaways e as faixas da regra
| vindas do Domain. Visitante sem sessão: tests/Feature/Security/GuestAccessTest.php.
|
*/

use App\Domain\Games\GiveawayPolicy;
use App\Models\AuthorizedUsers;
use App\Models\User;
use Tests\Support\GiveawayFactory;

function giveawaysPageUser(): User
{
    $user = User::factory()->create();
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    return $user;
}

describe('GET /giveaways', function () {

    it('renders the registered giveaways with the window rule', function () {
        GiveawayFactory::active('Dwarven Realms');

        $this->actingAs(giveawaysPageUser())
            ->get('/giveaways')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Giveaways')
                ->has('giveaways', 1)
                ->where('giveaways.0.game_name', 'Dwarven Realms')
                ->where('giveaways.0.is_flagged', true)
                ->where('rule', GiveawayPolicy::windowRule())
            );
    });

    it('renders an empty list before the first sync', function () {
        $this->actingAs(giveawaysPageUser())
            ->get('/giveaways')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->component('Giveaways')->has('giveaways', 0));
    });

    it('refuses a logged-in user outside the team', function () {
        $this->actingAs(User::factory()->create())
            ->get('/giveaways')
            ->assertStatus(403);
    });
});
