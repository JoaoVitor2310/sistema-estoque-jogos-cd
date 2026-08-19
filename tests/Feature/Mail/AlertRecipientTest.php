<?php

/*
|--------------------------------------------------------------------------
| AlertRecipientTest — guarda do destinatário dos alertas
|--------------------------------------------------------------------------
|
| Todo alerta do sistema vai para config('app.admin_email'), que lê ADMIN_EMAIL
| sem fallback: em branco, ninguém é admin e nenhum alerta é entregue.
|
| Como nenhum teste alcança o .env de um servidor, a guarda possível é sobre o
| arquivo versionado do qual todo ambiente novo nasce — .env.example, que declara
| a chave e não carrega endereço nenhum — e sobre o comportamento quando a config
| falta: o envio lança, e o UseCase precisa absorver a exceção para não derrubar
| as outras tarefas do scheduler.
|
| A suíte em si roda com ADMIN_EMAIL fixado no phpunit.xml. Deixá-la depender do
| .env de quem executa faria os testes de alerta ficarem vermelhos por
| configuração de máquina, não por regressão.
|
*/

use App\Mail\ExpiringKeysAlertMail;
use App\Models\User;
use App\UseCases\Keys\AlertExpiringKeysUseCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;

it('declares ADMIN_EMAIL in .env.example, and ships no address in it', function () {
    // A chave precisa estar listada: é assim que quem monta um ambiente novo
    // descobre que ela existe. O **valor** precisa estar vazio, e a razão mudou
    // de lado em 2026-08-19: `ADMIN_EMAIL` alimenta ao mesmo tempo o
    // destinatário dos alertas e a identidade do admin (`config/app.php`), e
    // este arquivo é versionado. Um endereço aqui é um endereço publicado — e,
    // pior, num deploy que esquecesse de trocá-lo, quem registrasse aquele
    // e-mail viraria admin. Em branco, o sistema nasce sem admin nenhum e sem
    // alerta entregue, que é o par de falhas seguro.
    $example = file_get_contents(base_path('.env.example'));

    preg_match('/^ADMIN_EMAIL=(.*)$/m', $example, $matches);

    expect($matches)->not->toBeEmpty()
        ->and(trim($matches[1]))->toBe('');
});

it('grants admin to nobody when ADMIN_EMAIL is missing', function () {
    // Entrega e autorização erram para lados opostos: sem destinatário o pior
    // desfecho é não avisar; sem identidade de admin, o pior desfecho seria
    // conceder acesso. Por isso a ausência nunca vira permissão.
    config(['app.admin_gate_email' => null]);

    $user = User::factory()->create(['email' => 'carcadeals@gmail.com']);

    expect(Gate::forUser($user)->allows('is-admin'))->toBeFalse();
});

it('logs instead of throwing when no admin recipient is configured', function () {
    config(['app.admin_email' => null]);

    DB::table('suppliers')->insert([
        'id' => 1,
        'url' => 'https://steamcommunity.com/id/seed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('keys')->insert([
        'key_code' => 'AAAAA-BBBBB-CCCCC',
        'supplier_id' => 1,
        'supplier_url' => 'https://steamcommunity.com/id/seed',
        'game_name' => 'Expiring Game',
        'identified_platform' => 'Steam',
        'region' => 'EU',
        'market_price' => 10,
        'individual_cost' => 5,
        'min_api' => 7,
        'max_api' => 40,
        'acquired_at' => now()->subMonths(2)->toDateString(),
        'expires_at' => now()->addDays(10)->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Sem Mail::fake() de propósito: é o mailer real que recusa um envio sem
    // destinatário, e é essa exceção que o UseCase tem de absorver.
    config(['mail.default' => 'array']);

    expect(fn () => app(AlertExpiringKeysUseCase::class)->execute())->not->toThrow(Exception::class);
});

it('addresses the alert to the configured admin', function () {
    Mail::fake();

    DB::table('suppliers')->insert([
        'id' => 1,
        'url' => 'https://steamcommunity.com/id/seed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('keys')->insert([
        'key_code' => 'AAAAA-BBBBB-CCCCC',
        'supplier_id' => 1,
        'supplier_url' => 'https://steamcommunity.com/id/seed',
        'game_name' => 'Expiring Game',
        'identified_platform' => 'Steam',
        'region' => 'EU',
        'market_price' => 10,
        'individual_cost' => 5,
        'min_api' => 7,
        'max_api' => 40,
        'acquired_at' => now()->subMonths(2)->toDateString(),
        'expires_at' => now()->addDays(10)->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(AlertExpiringKeysUseCase::class)->execute();

    Mail::assertSent(ExpiringKeysAlertMail::class, fn ($mail) => $mail->hasTo(config('app.admin_email')));
});
