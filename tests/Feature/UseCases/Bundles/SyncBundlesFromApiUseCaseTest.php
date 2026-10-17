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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
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
function fakeSyncHttp(array $bundles, ?int $currencyStatus = 200, ?array $research = null): void
{
    Http::fake([
        '*/api/games/research' => Http::response($research ?? ['success' => false], 200),
        'api.gg.deals/*' => Http::response(['data' => ['bundles' => $bundles]], 200),
        'economia.awesomeapi.com.br/*' => Http::response([
            'USDBRL' => ['high' => '5.10', 'low' => '4.90'],
            'EURBRL' => ['high' => '6.10', 'low' => '5.90'],
        ], $currencyStatus),
    ]);
}

/**
 * Duas rodadas da sync do mesmo bundle em euro, com uma resposta do
 * price_researcher por rodada — um fake só, porque fakes registrados depois
 * não substituem os anteriores.
 *
 * @param  array<int, mixed>  $researchResponses
 */
function fakeLaunchPriceRuns(array $researchResponses): void
{
    Http::fake([
        '*/api/games/research' => Http::sequence($researchResponses),
        'api.gg.deals/*' => Http::response(['data' => ['bundles' => [ggDealsBundle(10.00, 'EUR')]]], 200),
    ]);
}

function seedTf2Price(float $euro): void
{
    Asset::create(['name' => 'TF2', 'price_euro' => $euro, 'price_dollar' => 2.40, 'price_brl' => 12.00]);
}

beforeEach(function () {
    Mail::fake();
    config(['services.price_researcher.internal_secret' => 'segredo-de-teste']);
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

describe('launch prices', function () {

    // O bundle de teste é lançado em 2026-09-01: o relógio fica dentro da
    // janela de pesquisa do preço de lançamento, salvo onde o teste o move.
    beforeEach(fn () => $this->travelTo(Carbon::parse('2026-09-01 12:00')));

    it('asks the price_researcher with the bundle-sync preset and synchronous delivery', function () {
        seedTf2Price(2.00);
        fakeSyncHttp([ggDealsBundle(10.00, 'EUR')]);

        app(SyncBundlesFromApiUseCase::class)->execute();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/games/research')
            && $request['preset'] === 'bundle-sync'
            && $request['delivery'] === 'respond'
            && $request['gameNames'] === ['Portal 2']
            && $request['internal_secret'] === 'segredo-de-teste');
    });

    it('stores the market price of each game as its launch price', function () {
        seedTf2Price(2.00);
        fakeSyncHttp([ggDealsBundle(10.00, 'EUR')], research: [
            'success' => true,
            'games' => [['name' => 'Portal 2', 'market_price_euro' => 0.09, 'popularity' => 0, 'region' => '', 'id_steam' => null, 'gamivo_id' => null]],
        ]);

        app(SyncBundlesFromApiUseCase::class)->execute();

        expect((float) DB::table('bundle_games')->value('bundle_launch_price'))->toBe(0.09);
    });

    it('stores nothing when the price_researcher answers in demo mode', function () {
        // Demo devolve só os primeiros jogos: gravá-los daria o preço de um
        // bundle cortado pela metade como se fosse o resultado completo.
        seedTf2Price(2.00);
        fakeSyncHttp([ggDealsBundle(10.00, 'EUR')], research: [
            'success' => true,
            'demo' => true,
            'games' => [['name' => 'Portal 2', 'market_price_euro' => 0.09]],
        ]);

        app(SyncBundlesFromApiUseCase::class)->execute();

        expect(DB::table('bundle_games')->value('bundle_launch_price'))->toBeNull();
    });

    it('does not call the price_researcher without the internal secret', function () {
        config(['services.price_researcher.internal_secret' => null]);
        seedTf2Price(2.00);
        fakeSyncHttp([ggDealsBundle(10.00, 'EUR')]);

        app(SyncBundlesFromApiUseCase::class)->execute();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/games/'));
        // O bundle em si continua sincronizado.
        expect((float) DB::table('bundles')->value('price_euro'))->toBe(10.00);
    });

    it('matches a game the price_researcher returns with different punctuation or casing', function () {
        seedTf2Price(2.00);
        fakeSyncHttp([ggDealsBundle(10.00, 'EUR')], research: [
            'success' => true,
            'games' => [['name' => 'portal 2!', 'market_price_euro' => 1.50]],
        ]);

        app(SyncBundlesFromApiUseCase::class)->execute();

        expect((float) DB::table('bundle_games')->value('bundle_launch_price'))->toBe(1.50);
    });

    it('retries on a later run when the first research failed, while still inside the window', function () {
        // Antes só o bundle recém-criado era pesquisado: uma falha na primeira
        // rodada deixava o bundle sem preço de lançamento para sempre.
        seedTf2Price(2.00);
        fakeLaunchPriceRuns([
            Http::response(['success' => false], 500),
            Http::response(['success' => true, 'games' => [['name' => 'Portal 2', 'market_price_euro' => 0.09]]], 200),
        ]);

        app(SyncBundlesFromApiUseCase::class)->execute();
        $this->travel(1)->hours();
        app(SyncBundlesFromApiUseCase::class)->execute();

        expect((float) DB::table('bundle_games')->value('bundle_launch_price'))->toBe(0.09);
    });

    it('does not research again once the bundle has launch prices', function () {
        seedTf2Price(2.00);
        fakeLaunchPriceRuns([
            Http::response(['success' => true, 'games' => [['name' => 'Portal 2', 'market_price_euro' => 0.09]]], 200),
            Http::response(['success' => true, 'games' => [['name' => 'Portal 2', 'market_price_euro' => 5.00]]], 200),
        ]);

        app(SyncBundlesFromApiUseCase::class)->execute();
        $this->travel(1)->hours();
        app(SyncBundlesFromApiUseCase::class)->execute();

        $researchCalls = Http::recorded(fn ($request) => str_ends_with($request->url(), '/api/games/research'));

        expect($researchCalls)->toHaveCount(1);
        expect((float) DB::table('bundle_games')->value('bundle_launch_price'))->toBe(0.09);
    });

    it('does not research a bundle past the launch window', function () {
        seedTf2Price(2.00);
        $this->travelTo(Carbon::parse('2026-09-10'));
        fakeSyncHttp([ggDealsBundle(10.00, 'EUR')]);

        app(SyncBundlesFromApiUseCase::class)->execute();

        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/games/research'));
    });

    it('keeps the bundle synchronized when the price_researcher fails', function () {
        seedTf2Price(2.00);
        fakeSyncHttp([ggDealsBundle(10.00, 'EUR')], research: ['success' => false, 'error' => 'Internal server error']);

        app(SyncBundlesFromApiUseCase::class)->execute();

        expect((float) DB::table('bundles')->value('price_euro'))->toBe(10.00)
            ->and(DB::table('bundle_games')->count())->toBe(1)
            ->and(DB::table('bundle_games')->value('bundle_launch_price'))->toBeNull();
    });

    it('keeps the bundle synchronized when the price_researcher is unreachable', function () {
        seedTf2Price(2.00);
        Http::fake([
            '*/api/games/research' => fn () => throw new ConnectionException('Connection timed out'),
            'api.gg.deals/*' => Http::response(['data' => ['bundles' => [ggDealsBundle(10.00, 'EUR')]]], 200),
        ]);

        app(SyncBundlesFromApiUseCase::class)->execute();

        expect((float) DB::table('bundles')->value('price_euro'))->toBe(10.00)
            ->and(DB::table('bundle_games')->count())->toBe(1);
    });
});
