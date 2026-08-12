<?php

/*
|--------------------------------------------------------------------------
| Route model binding — contrato do 404
|--------------------------------------------------------------------------
|
| Os controllers recebem o model direto da rota em vez de fazer find() à mão.
| Quem perde o find() perde também o `$this->error(404, ...)` que vinha junto,
| então o envelope do HttpResponses (statusCode/message/errors/data) passou a
| ser responsabilidade do handler em bootstrap/app.php.
|
| Este arquivo é a guarda desse envelope: sem ele, o 404 volta ao formato
| padrão do Laravel e o frontend perde `data.errors`.
|
*/

use App\Models\AuthorizedUsers;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function actingAsBindingUser(): User
{
    $user = User::factory()->create(['email' => 'admin@carcadeals.test']);
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    // /fees e /authorize exigem CheckAdmin, as demais só can-edit.
    config(['app.admin_gate_email' => $user->email]);

    return $user;
}

beforeEach(fn () => $this->actingAs(actingAsBindingUser()));

it('answers 404 in the HttpResponses envelope', function () {
    $this->deleteJson('/games/999999')
        ->assertStatus(404)
        ->assertJson([
            'statusCode' => 404,
            'message' => 'Jogo não encontrado',
            'errors' => [],
            'data' => [],
        ]);
});

it('names the missing model in the message', function () {
    // Cada model tem seu rótulo; um genérico faria toda tela dizer a mesma coisa.
    $this->deleteJson('/fees/999999')->assertJsonPath('message', 'Taxa não encontrada');
    $this->deleteJson('/keys/999999')->assertJsonPath('message', 'Key não encontrada');
});

it('falls back to a generic message for a model without a label', function () {
    $this->deleteJson('/authorize/999999')->assertJsonPath('message', 'Registro não encontrado');
});

it('resolves the model when the record exists', function () {
    $id = DB::table('games')->insertGetId([
        'name' => 'Portal 2',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->deleteJson("/games/{$id}")->assertStatus(200);

    expect(DB::table('games')->count())->toBe(0);
});

it('leaves an unknown URL to the fallback route', function () {
    // O handler cobre binding que não achou registro, não rota inexistente:
    // são erros diferentes, e URL desconhecida continua caindo no
    // Route::fallback que redireciona para /keys.
    $this->getJson('/rota-que-nao-existe')->assertRedirect(route('keys'));
});
