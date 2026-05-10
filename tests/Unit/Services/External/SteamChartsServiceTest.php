<?php

/*
|--------------------------------------------------------------------------
| SteamChartsServiceTest — unit tests
|--------------------------------------------------------------------------
|
| Cobre a extração de popularidade do HTML do SteamCharts.
| Todos os requests HTTP são interceptados via Http::fake().
|
*/

use App\Services\External\SteamChartsService;
use Illuminate\Support\Facades\Http;

describe('SteamChartsService', function () {

    // ── Parsing ───────────────────────────────────────────────────────────────

    it('returns the second span.num value as the 24h peak', function () {
        // Primeiro span = média mensal, segundo = pico 24h
        Http::fake([
            'steamcharts.com/*' => Http::response(
                '<span class="num">1,234</span><span class="num">567</span>',
                200
            ),
        ]);

        $result = app(SteamChartsService::class)->getPopularity('440');

        expect($result)->toBe(567);
    });

    it('strips thousands separators before converting to int', function () {
        Http::fake([
            'steamcharts.com/*' => Http::response(
                '<span class="num">5,000</span><span class="num">1,250</span>',
                200
            ),
        ]);

        $result = app(SteamChartsService::class)->getPopularity('440');

        expect($result)->toBe(1250);
    });

    it('returns 0 when the page has fewer than two span.num elements', function () {
        Http::fake([
            'steamcharts.com/*' => Http::response(
                '<span class="num">999</span>',
                200
            ),
        ]);

        $result = app(SteamChartsService::class)->getPopularity('440');

        expect($result)->toBe(0);
    });

    it('returns 0 when the page has no span.num elements', function () {
        Http::fake([
            'steamcharts.com/*' => Http::response('<html><body>Not found</body></html>', 200),
        ]);

        $result = app(SteamChartsService::class)->getPopularity('440');

        expect($result)->toBe(0);
    });

    // ── Resiliência ───────────────────────────────────────────────────────────

    it('returns 0 when the HTTP request fails', function () {
        Http::fake([
            'steamcharts.com/*' => Http::response('', 503),
        ]);

        $result = app(SteamChartsService::class)->getPopularity('440');

        expect($result)->toBe(0);
    });

    it('returns 0 when the HTTP request throws a connection exception', function () {
        Http::fake([
            'steamcharts.com/*' => Http::failedConnection(),
        ]);

        $result = app(SteamChartsService::class)->getPopularity('440');

        expect($result)->toBe(0);
    });
});
