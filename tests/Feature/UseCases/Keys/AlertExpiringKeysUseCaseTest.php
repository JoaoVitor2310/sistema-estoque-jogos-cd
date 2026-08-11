<?php

/*
|--------------------------------------------------------------------------
| AlertExpiringKeysUseCase — integration
|--------------------------------------------------------------------------
|
| Cobre a janela do alerta (KeyEligibility::EXPIRY_ALERT_DAYS), as exclusões
| (key já vendida, key sem data de expiração, key já expirada) e o silêncio
| quando não há nada a avisar.
|
*/

use App\Domain\Keys\KeyEligibility;
use App\Mail\ExpiringKeysAlertMail;
use App\UseCases\Keys\AlertExpiringKeysUseCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

function seedExpiringKey(array $overrides = []): int
{
    DB::table('suppliers')->insertOrIgnore([
        'id' => 1,
        'url' => 'https://steamcommunity.com/id/seed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return DB::table('keys')->insertGetId(array_merge([
        'key_code' => 'AAAAA-BBBBB-'.fake()->numerify('#####'),
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
        'sold_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

beforeEach(fn () => Mail::fake());

it('alerts about a key expiring inside the window', function () {
    seedExpiringKey();

    expect(app(AlertExpiringKeysUseCase::class)->execute())->toBe(1);

    Mail::assertSent(ExpiringKeysAlertMail::class, fn ($mail) => $mail->keys->count() === 1
        && $mail->hasTo(config('app.admin_email')));
});

it('sends nothing when no key is expiring', function () {
    seedExpiringKey(['expires_at' => now()->addDays(KeyEligibility::EXPIRY_ALERT_DAYS + 5)->toDateString()]);

    expect(app(AlertExpiringKeysUseCase::class)->execute())->toBe(0);

    Mail::assertNothingSent();
});

it('ignores a key that was already sold', function () {
    seedExpiringKey(['sold_at' => now()->subDay()->toDateString()]);

    expect(app(AlertExpiringKeysUseCase::class)->execute())->toBe(0);

    Mail::assertNothingSent();
});

it('ignores a key with no expiry date', function () {
    seedExpiringKey(['expires_at' => null]);

    expect(app(AlertExpiringKeysUseCase::class)->execute())->toBe(0);
});

it('ignores a key that already expired', function () {
    // Passado o prazo não há mais o que fazer — o alerta serve para agir a tempo.
    seedExpiringKey(['expires_at' => now()->subDay()->toDateString()]);

    expect(app(AlertExpiringKeysUseCase::class)->execute())->toBe(0);
});

it('includes a key sitting exactly on the window boundary', function () {
    seedExpiringKey(['expires_at' => now()->addDays(KeyEligibility::EXPIRY_ALERT_DAYS)->toDateString()]);

    expect(app(AlertExpiringKeysUseCase::class)->execute())->toBe(1);
});

it('renders the supplier profile in the email body', function () {
    // A coluna Perfil/Origem lia uma relação `fornecedor` que não existe no
    // model Key, então saía vazia em toda linha.
    seedExpiringKey();

    $rendered = (new ExpiringKeysAlertMail(
        App\Models\Key::with('supplier')->get()
    ))->render();

    expect($rendered)->toContain('https://steamcommunity.com/id/seed')
        ->and($rendered)->not->toContain('Não encontrado');
});
