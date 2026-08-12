<?php

/*
|--------------------------------------------------------------------------
| UpdateAssetPricesUseCase — integration
|--------------------------------------------------------------------------
|
| A moeda âncora (`currentCurrency`) significa "este é o valor que eu sei;
| derive os outros". Sem ela, os três preços são gravados como vieram.
|
| O contrato HTTP em volta disso vive em tests/Feature/Assets/UpdateAssetTest.
|
*/

use App\Models\Asset;
use App\UseCases\Assets\UpdateAssetPricesUseCase;
use Illuminate\Support\Facades\Http;

function seedTf2Asset(): Asset
{
    return Asset::create([
        'name' => 'TF2',
        'price_brl' => 10.0,
        'price_dollar' => 2.0,
        'price_euro' => 1.8,
    ]);
}

function fakeRates(float $usd, float $eur): void
{
    Http::fake(['*economia.awesomeapi.com.br*' => Http::response([
        'USDBRL' => ['high' => (string) $usd, 'low' => (string) $usd],
        'EURBRL' => ['high' => (string) $eur, 'low' => (string) $eur],
    ], 200)]);
}

it('derives the other currencies from the anchor', function () {
    fakeRates(5.0, 6.0);

    $asset = app(UpdateAssetPricesUseCase::class)->execute(seedTf2Asset(), [
        'name' => 'TF2',
        'price_brl' => '30.00',
        'price_dollar' => '0.00',
        'price_euro' => '0.00',
        'currentCurrency' => 'BRL',
    ]);

    expect((float) $asset->price_brl)->toBe(30.0)
        ->and((float) $asset->price_dollar)->toBe(6.0)
        ->and((float) $asset->price_euro)->toBe(5.0);
});

it('anchors on the dollar when the dollar is declared', function () {
    fakeRates(5.0, 6.0);

    $asset = app(UpdateAssetPricesUseCase::class)->execute(seedTf2Asset(), [
        'name' => 'TF2',
        'price_brl' => '0.00',
        'price_dollar' => '2.00',
        'price_euro' => '0.00',
        'currentCurrency' => 'USD',
    ]);

    expect((float) $asset->price_brl)->toBe(10.0)
        ->and((float) $asset->price_dollar)->toBe(2.0);
});

it('writes the submitted prices when no anchor is declared', function () {
    Http::fake();

    $asset = app(UpdateAssetPricesUseCase::class)->execute(seedTf2Asset(), [
        'name' => 'TF2',
        'price_brl' => '11.00',
        'price_dollar' => '2.20',
        'price_euro' => '1.90',
    ]);

    expect((float) $asset->price_brl)->toBe(11.0)
        ->and((float) $asset->price_dollar)->toBe(2.20);

    Http::assertNothingSent();
});

it('keeps the submitted prices when the currency API fails', function () {
    // convertCurrency devolve o valor de ENTRADA quando a API cai. Repassar isso
    // gravaria price_dollar = 30.00 (o montante em real) por cima do enviado.
    Http::fake(['*economia.awesomeapi.com.br*' => Http::response([], 500)]);

    $asset = app(UpdateAssetPricesUseCase::class)->execute(seedTf2Asset(), [
        'name' => 'TF2',
        'price_brl' => '30.00',
        'price_dollar' => '2.20',
        'price_euro' => '1.90',
        'currentCurrency' => 'BRL',
    ]);

    expect((float) $asset->price_brl)->toBe(30.0)
        ->and((float) $asset->price_dollar)->toBe(2.20)
        ->and((float) $asset->price_euro)->toBe(1.90);
});

it('persists the result', function () {
    fakeRates(5.0, 6.0);

    $asset = seedTf2Asset();

    app(UpdateAssetPricesUseCase::class)->execute($asset, [
        'name' => 'TF2',
        'price_brl' => '30.00',
        'price_dollar' => '0.00',
        'price_euro' => '0.00',
        'currentCurrency' => 'BRL',
    ]);

    expect((float) $asset->fresh()->price_dollar)->toBe(6.0);
});
