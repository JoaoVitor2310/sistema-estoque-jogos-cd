<?php

/*
|--------------------------------------------------------------------------
| KeyCalculationService — preço da TF2 por moeda
|--------------------------------------------------------------------------
|
| O preço da TF2 em euro e dólar é cacheado por uma hora, mas só quando
| positivo: preço zerado é "ainda não cadastrado", e cacheá-lo travaria o
| sistema numa cotação inexistente depois de a equipe cadastrá-la.
|
*/

use App\Domain\Enums\TradeCurrency;
use App\Services\Keys\KeyCalculationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Cache::flush();

    DB::table('assets')->insert([
        'name' => 'TF2', 'price_euro' => 2.0, 'price_dollar' => 0, 'price_brl' => 10.0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('returns the TF2 price in each currency', function () {
    DB::table('assets')->where('name', 'TF2')->update(['price_dollar' => 2.2]);
    $service = app(KeyCalculationService::class);

    expect($service->getTf2Price(TradeCurrency::Tf2))->toBe(1.0)
        ->and($service->getTf2Price(TradeCurrency::Eur))->toBe(2.0)
        ->and($service->getTf2Price(TradeCurrency::Usd))->toBe(2.2);
});

it('does not cache a missing price, so registering it takes effect at once', function () {
    $service = app(KeyCalculationService::class);

    expect($service->getTf2DollarPrice())->toBe(0.0);

    DB::table('assets')->where('name', 'TF2')->update(['price_dollar' => 2.2]);

    expect($service->getTf2DollarPrice())->toBe(2.2);
});

it('caches a positive price for the hour', function () {
    $service = app(KeyCalculationService::class);

    expect($service->getTf2EuroPrice())->toBe(2.0);

    DB::table('assets')->where('name', 'TF2')->update(['price_euro' => 3.0]);

    expect($service->getTf2EuroPrice())->toBe(2.0);
});
