<?php

/*
|--------------------------------------------------------------------------
| UpdatePopularityUseCase — feature tests
|--------------------------------------------------------------------------
|
| Cobre a atualização de popularidade dos jogos via scraping do SteamCharts.
| Todos os requests HTTP são interceptados via Http::fake().
|
| Regras:
|   - Apenas jogos com steam_id são processados.
|   - A popularidade é extraída do segundo <span class="num"> da página.
|   - Falhas no SteamCharts gravam 0 — nunca interrompem o loop.
|   - O array de IDs retornado contém todos os jogos processados.
|
*/

use App\UseCases\Marketplaces\Gamivo\UpdatePopularityUseCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Insere um jogo com steam_id e retorna o ID gerado.
 */
function insertGameWithSteamId(string $steamId, string $name = 'Test Game'): int
{
    return DB::table('games')->insertGetId([
        'name' => $name,
        'steam_id' => $steamId,
        'popularity' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * HTML mínimo do SteamCharts com dois spans — primeiro = média mensal, segundo = pico 24h.
 */
function steamChartsHtml(int $monthly, int $peak24h): string
{
    $m = number_format($monthly, 0, '.', ',');
    $p = number_format($peak24h, 0, '.', ',');

    return "<span class=\"num\">{$m}</span><span class=\"num\">{$p}</span>";
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('UpdatePopularityUseCase', function () {

    // ── Happy path ────────────────────────────────────────────────────────────

    it('updates popularity for a single game', function () {
        $id = insertGameWithSteamId('440', 'Team Fortress 2');

        Http::fake([
            'steamcharts.com/app/440*' => Http::response(steamChartsHtml(12000, 18500), 200),
        ]);

        app(UpdatePopularityUseCase::class)->execute();

        $popularity = DB::table('games')->where('id', $id)->value('popularity');

        expect($popularity)->toBe(18500);
    });

    it('updates all games that have a steam_id', function () {
        $id1 = insertGameWithSteamId('440', 'TF2');
        $id2 = insertGameWithSteamId('730', 'CS2');

        Http::fake([
            'steamcharts.com/app/440*' => Http::response(steamChartsHtml(10000, 15000), 200),
            'steamcharts.com/app/730*' => Http::response(steamChartsHtml(50000, 80000), 200),
        ]);

        app(UpdatePopularityUseCase::class)->execute();

        expect(DB::table('games')->where('id', $id1)->value('popularity'))->toBe(15000)
            ->and(DB::table('games')->where('id', $id2)->value('popularity'))->toBe(80000);
    });

    it('returns the IDs of all updated games', function () {
        $id1 = insertGameWithSteamId('440', 'TF2');
        $id2 = insertGameWithSteamId('730', 'CS2');

        Http::fake([
            'steamcharts.com/*' => Http::response(steamChartsHtml(1000, 2000), 200),
        ]);

        $result = app(UpdatePopularityUseCase::class)->execute();

        expect($result)->toContain($id1)->toContain($id2)->toHaveCount(2);
    });

    // ── Resiliência ───────────────────────────────────────────────────────────

    it('records 0 when SteamCharts returns HTTP 503', function () {
        $id = insertGameWithSteamId('440');

        Http::fake([
            'steamcharts.com/*' => Http::response('', 503),
        ]);

        app(UpdatePopularityUseCase::class)->execute();

        expect(DB::table('games')->where('id', $id)->value('popularity'))->toBe(0);
    });

    it('records 0 when SteamCharts page has no span.num elements', function () {
        $id = insertGameWithSteamId('440');

        Http::fake([
            'steamcharts.com/*' => Http::response('<html><body>Not found</body></html>', 200),
        ]);

        app(UpdatePopularityUseCase::class)->execute();

        expect(DB::table('games')->where('id', $id)->value('popularity'))->toBe(0);
    });

    it('still processes subsequent games when one SteamCharts request fails', function () {
        $id1 = insertGameWithSteamId('440', 'TF2');
        $id2 = insertGameWithSteamId('730', 'CS2');

        Http::fake([
            'steamcharts.com/app/440*' => Http::response('', 503),
            'steamcharts.com/app/730*' => Http::response(steamChartsHtml(5000, 9000), 200),
        ]);

        $result = app(UpdatePopularityUseCase::class)->execute();

        // Ambos os jogos devem ter sido processados — ID1 com 0, ID2 com 9000
        expect(DB::table('games')->where('id', $id1)->value('popularity'))->toBe(0)
            ->and(DB::table('games')->where('id', $id2)->value('popularity'))->toBe(9000)
            ->and($result)->toHaveCount(2);
    });

    // ── Exclusion ─────────────────────────────────────────────────────────────

    it('returns an empty array when there are no games with steam_id', function () {
        // Insere jogo sem steam_id — não deve ser processado
        DB::table('games')->insert([
            'name' => 'No Steam Game',
            'steam_id' => null,
            'popularity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake(); // Nenhum request deve ocorrer

        $result = app(UpdatePopularityUseCase::class)->execute();

        expect($result)->toBeEmpty();
        Http::assertNothingSent();
    });
});
