<?php

/*
|--------------------------------------------------------------------------
| SyncBundlesFromApiUseCase — integration
|--------------------------------------------------------------------------
|
| O preço do bundle é guardado em **euro** (a coluna `price_euro`), e o
| `minimum_price_tf2` é a razão entre ele e o preço de uma TF2 key — as duas
| pontas em euro, para a razão não depender de duas cotações diferentes.
|
| Três integrações externas entram no fluxo e todas são interceptadas por
| Http::fake(): GGDeals (lista de bundles), AwesomeAPI (cotação) e o
| price_researcher (preço de lançamento dos jogos).
|
*/

use App\Mail\BundlePriceConversionFailedMail;
use App\Models\Asset;
use App\UseCases\Bundles\SyncBundlesFromApiUseCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/** Um bundle da GGDeals com um único tier. */
function ggDealsBundle(float $price, string $currency, string $title = 'Humble Choice Setembro'): array
{
    return [
        'title' => $title,
        'url' => 'https://gg.deals/bundle/'.md5($title),
        'dateFrom' => '2026-09-01',
        'dateTo' => '2026-09-30',
        'tiers' => [
            [
                'price' => $price,
                'currency' => $currency,
                'games' => [['title' => 'Portal 2']],
            ],
        ],
    ];
}

/**
 * Cotações da AwesomeAPI: EUR a 6,00 e USD a 5,00 no meio da faixa, então
 * 12 USD = 60 BRL = 10 EUR.
 */
function fakeSyncHttp(array $bundles, ?int $currencyStatus = 200): void
{
    Http::fake([
        'api.gg.deals/*' => Http::response(['data' => ['bundles' => $bundles]], 200),
        'economia.awesomeapi.com.br/*' => Http::response([
            'USDBRL' => ['high' => '5.10', 'low' => '4.90'],
            'EURBRL' => ['high' => '6.10', 'low' => '5.90'],
        ], $currencyStatus),
        // O preço de lançamento não é o eixo destes testes: resposta vazia.
        '*' => Http::response(['success' => false], 200),
    ]);
}

function seedTf2Price(float $euro): void
{
    Asset::create(['name' => 'TF2', 'price_euro' => $euro, 'price_dollar' => 2.40, 'price_brl' => 12.00]);
}

beforeEach(function () {
    Mail::fake();
});

it('persists the tier price as it came when the tier is already in euro', function () {
    seedTf2Price(2.00);
    fakeSyncHttp([ggDealsBundle(10.00, 'EUR')]);

    app(SyncBundlesFromApiUseCase::class)->execute();

    expect((float) DB::table('bundles')->value('price_euro'))->toBe(10.00);
    // Moedas iguais não chamam a AwesomeAPI.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'awesomeapi'));
});

it('converts the tier price to euro when the tier comes in another currency', function () {
    seedTf2Price(2.00);
    fakeSyncHttp([ggDealsBundle(12.00, 'USD')]);

    app(SyncBundlesFromApiUseCase::class)->execute();

    expect((float) DB::table('bundles')->value('price_euro'))->toBe(10.00);
});

it('computes minimum_price_tf2 dividing the euro price by the euro price of a tf2 key', function () {
    seedTf2Price(2.00);
    fakeSyncHttp([ggDealsBundle(10.00, 'EUR')]);

    app(SyncBundlesFromApiUseCase::class)->execute();

    // 10 EUR de bundle / 2 EUR por key = 5 keys.
    expect((float) DB::table('bundles')->value('minimum_price_tf2'))->toBe(5.00);
});

it('keeps the bundle price and leaves minimum_price_tf2 empty when the tf2 price is missing', function () {
    // Sem cotação da TF2 na base, a razão não existe — mas o preço do bundle
    // já vale, e dividir por zero derrubaria a sincronização inteira.
    fakeSyncHttp([ggDealsBundle(10.00, 'EUR')]);

    app(SyncBundlesFromApiUseCase::class)->execute();

    $bundle = DB::table('bundles')->first();

    expect((float) $bundle->price_euro)->toBe(10.00)
        ->and($bundle->minimum_price_tf2)->toBeNull();
});

it('skips the bundle and warns by email when the conversion fails', function () {
    seedTf2Price(2.00);
    fakeSyncHttp([ggDealsBundle(12.00, 'USD')], currencyStatus: 429);

    app(SyncBundlesFromApiUseCase::class)->execute();

    // O bundle é criado pelo firstOrCreate antes do preço, mas fica sem preço
    // e sem jogos: a próxima rodada tenta de novo.
    expect(DB::table('bundles')->value('price_euro'))->toBeNull()
        ->and(DB::table('bundle_games')->count())->toBe(0);

    Mail::assertSent(BundlePriceConversionFailedMail::class);
});
