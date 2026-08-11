<?php

/*
|--------------------------------------------------------------------------
| PUT /assets/{id} — contrato HTTP
|--------------------------------------------------------------------------
|
| A tela envia os três preços e, opcionalmente, `currentCurrency` — a moeda que
| serve de âncora. Declarada, as outras duas são convertidas; ausente, os três
| valores enviados são gravados como vieram.
|
*/

use App\Models\AuthorizedUsers;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function seedAsset(): int
{
    return DB::table('assets')->insertGetId([
        'name' => 'TF2',
        'price_brl' => 10.0,
        'price_dollar' => 2.0,
        'price_euro' => 1.8,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** As rotas de mutação de assets exigem admin (CheckAdmin), não só can-edit. */
function actingAsAssetAdmin(): User
{
    $user = User::factory()->create(['email' => 'admin@carcadeals.test']);
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    config(['app.admin_gate_email' => $user->email]);

    return $user;
}

beforeEach(function () {
    $this->assetId = seedAsset();
    $this->actingAs(actingAsAssetAdmin());
});

it('converts the other currencies from the declared base', function () {
    Http::fake(['*economia.awesomeapi.com.br*' => Http::response([
        'USDBRL' => ['high' => '5.0', 'low' => '5.0'],
        'EURBRL' => ['high' => '6.0', 'low' => '6.0'],
    ], 200)]);

    $this->putJson("/assets/{$this->assetId}", [
        'name' => 'TF2',
        'price_brl' => '30.00',
        'price_dollar' => '0.00',
        'price_euro' => '0.00',
        'currentCurrency' => 'BRL',
    ])->assertStatus(200);

    $asset = DB::table('assets')->find($this->assetId);

    expect((float) $asset->price_brl)->toBe(30.0)
        ->and((float) $asset->price_dollar)->toBe(6.0)
        ->and((float) $asset->price_euro)->toBe(5.0);
});

it('keeps the submitted prices when no base currency is declared', function () {
    Http::fake();

    $this->putJson("/assets/{$this->assetId}", [
        'name' => 'TF2',
        'price_brl' => '11.00',
        'price_dollar' => '2.20',
        'price_euro' => '1.90',
    ])->assertStatus(200);

    $asset = DB::table('assets')->find($this->assetId);

    expect((float) $asset->price_brl)->toBe(11.0)
        ->and((float) $asset->price_dollar)->toBe(2.20)
        ->and((float) $asset->price_euro)->toBe(1.90);

    Http::assertNothingSent();
});

it('keeps the submitted prices when the currency API fails', function () {
    // Regressão: convertCurrency devolve o valor de ENTRADA quando a API cai.
    // Repassar isso gravaria price_dollar = 30.00 (o montante em real) por cima
    // do valor enviado — número plausível e errado, persistido em silêncio.
    Http::fake(['*economia.awesomeapi.com.br*' => Http::response([], 500)]);

    $this->putJson("/assets/{$this->assetId}", [
        'name' => 'TF2',
        'price_brl' => '30.00',
        'price_dollar' => '2.20',
        'price_euro' => '1.90',
        'currentCurrency' => 'BRL',
    ])->assertStatus(200);

    $asset = DB::table('assets')->find($this->assetId);

    expect((float) $asset->price_brl)->toBe(30.0)
        ->and((float) $asset->price_dollar)->toBe(2.20)
        ->and((float) $asset->price_euro)->toBe(1.90);
});

it('blocks a non-admin', function () {
    config(['app.admin_gate_email' => 'someone.else@carcadeals.test']);

    $this->putJson("/assets/{$this->assetId}", [])->assertStatus(403);
});
