<?php

/*
|--------------------------------------------------------------------------
| RegisteredUserAccessTest — o que uma conta criada de fora alcança
|--------------------------------------------------------------------------
|
| `GuestAccessTest` cobre quem não está logado. Este cobre o degrau seguinte,
| que a entrega (docs/adr/0008) tornou concreto: `/register` é aberto e não pede
| verificação de e-mail, então qualquer pessoa que receba o link da entrega pode
| criar uma conta no mesmo domínio. O que essa conta alcança é a fronteira real.
|
| Casos testados:
|
|   Conta comum, fora da lista de autorizados:
|     1. registra sem verificação nenhuma de e-mail
|     2. não recebe key_code em /keys/search
|     3. não abre a aba de trades, onde as keys são digitadas
|     4. não altera nada
|     5. não abre nenhuma página da equipe — nem as que não têm key_code:
|        o caixa da operação e a lista de acessos também são da equipe
|
|   O gate can-edit:
|     6. casa só por e-mail, sem prova de posse do endereço
|
*/

use App\Models\AuthorizedUsers;
use App\Models\Key;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Uma conta como a que qualquer visitante cria em `/register`. */
function outsider(): User
{
    return User::factory()->create(['email' => 'estranho@example.com']);
}

function seedKeyWithCode(): void
{
    DB::table('suppliers')->insertOrIgnore([
        'id' => 1,
        'url' => 'https://steamcommunity.com/id/seed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Key::factory()->create(['key_code' => 'SEGREDO-AAAAA-BBBBB']);
}

describe('an account created from outside', function () {

    it('registers with no e-mail verification at all', function () {
        $this->post('/register', [
            'name' => 'Estranho',
            'email' => 'novo@example.com',
            'password' => 'Senha-Muito-Longa-123',
            'password_confirmation' => 'Senha-Muito-Longa-123',
        ]);

        $user = User::where('email', 'novo@example.com')->first();

        expect($user)->not->toBeNull()
            ->and($user->email_verified_at)->toBeNull()
            ->and(auth()->check())->toBeTrue();
    });

    it('never receives a key_code', function () {
        seedKeyWithCode();

        $response = $this->actingAs(outsider())->postJson('/keys/search', []);

        expect(json_encode($response->json()))->not->toContain('SEGREDO-AAAAA-BBBBB');
    });

    it('cannot reach the trades tab, where the keys are typed', function () {
        $this->actingAs(outsider())->get('/trades')->assertStatus(403);
    });

    it('cannot change anything', function () {
        $user = outsider();

        $this->actingAs($user)->putJson('/keys/1', [])->assertStatus(403);
        $this->actingAs($user)->deleteJson('/keys/1')->assertStatus(403);
        $this->actingAs($user)->postJson('/suppliers', [])->assertStatus(403);
    });

    it('opens no team page at all', function () {
        // Antes abria todas: `RequireAuth` pedia sessão e mais nada, e criar
        // sessão é o que qualquer visitante faz em /register. Nenhuma delas
        // mostra key_code — mostram o caixa da operação e a lista de quem tem
        // acesso, que é a informação com que se monta o próximo ataque.
        //
        // 403 e não redirect: ele **tem** sessão. Mandá-lo para o login diria
        // que falta logar, quando o que falta é ser da equipe.
        $user = outsider();

        foreach (['/assets', '/sales', '/financial-months', '/games', '/fees', '/acesso'] as $page) {
            $this->actingAs($user)->get($page)->assertStatus(403);
        }
    });

    it('still sends a visitor with no session to the login page', function () {
        // A recusa tem duas caras porque são dois problemas: sem sessão, o
        // login resolve; com sessão e sem equipe, não há o que resolver.
        $this->get('/financial-months')->assertRedirect('/login');
    });
});

describe('the can-edit gate', function () {

    it('matches on e-mail alone, with no proof the address belongs to the user', function () {
        // Caracteriza o comportamento atual, não o desejado: a lista de
        // autorizados casa `authorized_users.email` com `users.email`, e nada
        // verifica que quem registrou aquele endereço é o dono dele. Só não é
        // explorável enquanto o e-mail autorizado já tiver conta — `users.email`
        // é único. Registrado em docs/IMPROVEMENTS.md.
        AuthorizedUsers::create([
            'name' => 'Sócio',
            'email' => 'socio@carcadeals.com',
            'status' => true,
        ]);

        seedKeyWithCode();

        $sameEmail = User::factory()->create(['email' => 'socio@carcadeals.com']);

        $response = $this->actingAs($sameEmail)->postJson('/keys/search', []);

        expect(json_encode($response->json()))->toContain('SEGREDO-AAAAA-BBBBB');
    });
});
