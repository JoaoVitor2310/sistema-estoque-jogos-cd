<?php

/*
|--------------------------------------------------------------------------
| AutoSellUseCase — feature tests
|--------------------------------------------------------------------------
|
| Cobre o fluxo completo de listagem automática de keys na Gamivo:
|  1. Filtragem de elegibilidade (gamivo_id, listed_at, sold_at, gift link, bundle)
|  2. Cálculo de preço via ComparisonAlgorithm
|  3. Criação de oferta via GamivoApiService
|  4. Upload da key com retry
|  5. Marcação de listed_at no banco
|
| Todos os requests HTTP são interceptados via Http::fake().
|
*/

use App\UseCases\Marketplaces\Gamivo\AutoSellUseCase;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Insere uma key elegível para auto-sell e retorna o ID gerado.
 * Usa gamivo_id numérico para compatibilidade com (int) cast no UseCase.
 */
function insertAutoSellKey(string $gamivoId = '440', array $overrides = []): int
{
    return DB::table('keys')->insertGetId(array_merge([
        'game_name' => 'Test Game',
        'gamivo_id' => $gamivoId,
        'key_code' => 'ABCDE-'.uniqid(),
        'market_price' => 5.00,
        'individual_cost' => 2.00,
        'min_api' => 2.00,
        'max_api' => 20.00,
        'purchase_profit_percent' => 25.00,
        'supplier_url' => 'https://steamcommunity.com/id/test',
        'supplier_id' => 1,
        'claim_type' => 'Nenhuma',
        'key_format' => 'RK',
        'sell_platform' => 'Gamivo',
        'listed_at' => null,
        'sold_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * Http::fake() padrão para o fluxo completo de uma key:
 * sem concorrentes → cria oferta → upload → job concluído → key verificada como ativa.
 */
function fakeGamivoAutoSell(): void
{
    Http::fake([
        '*/products/*/offers' => Http::response([], 200),
        '*/v1/offers' => Http::response(12345, 200),
        '*/offers/12345/keys/upload' => Http::response(999, 200),
        '*/offers/12345/jobs/999/result' => Http::response('"Done"', 200),
        '*/offers/12345/keys/active/0/1*' => Http::response(['count' => 1, 'data' => []], 200),
    ]);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('AutoSellUseCase', function () {

    beforeEach(function () {
        DB::table('fees')->insert([
            ['name' => 'gamivo_percent_low', 'preco' => 0.060, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'gamivo_fixed_low', 'preco' => 0.250, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'gamivo_percent_high', 'preco' => 0.080, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'gamivo_fixed_high', 'preco' => 0.400, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('suppliers')->insert(['id' => 1, 'supplier_url' => 'https://steamcommunity.com/id/seed']);

        Cache::flush();
    });

    // ── Happy path ────────────────────────────────────────────────────────────

    it('marks an eligible key as listed today', function () {
        fakeGamivoAutoSell();
        insertAutoSellKey('440');

        app(AutoSellUseCase::class)->execute();

        $listedAt = DB::table('keys')->where('gamivo_id', '440')->value('listed_at');

        expect($listedAt)->toBe(now()->toDateString());
    });

    it('returns the DB id of each listed key', function () {
        fakeGamivoAutoSell();
        $id = insertAutoSellKey('440');

        $result = app(AutoSellUseCase::class)->execute();

        expect($result)->toContain($id);
    });

    it('lists all eligible keys in a single run', function () {
        Http::fake([
            '*/products/*/offers' => Http::response([], 200),
            '*/v1/offers' => Http::sequence()->push(11111)->push(22222),
            '*/offers/11111/keys/upload' => Http::response(991, 200),
            '*/offers/22222/keys/upload' => Http::response(992, 200),
            '*/offers/11111/jobs/991/result' => Http::response('"Done"', 200),
            '*/offers/22222/jobs/992/result' => Http::response('"Done"', 200),
            '*/offers/11111/keys/active/0/1*' => Http::response(['count' => 1, 'data' => []], 200),
            '*/offers/22222/keys/active/0/1*' => Http::response(['count' => 1, 'data' => []], 200),
        ]);

        insertAutoSellKey('440');
        insertAutoSellKey('730');

        $result = app(AutoSellUseCase::class)->execute();

        expect($result)->toHaveCount(2)
            ->and(DB::table('keys')->whereNotNull('listed_at')->count())->toBe(2);
    });

    it('sends the correct product_id in the createOffer request', function () {
        fakeGamivoAutoSell();
        insertAutoSellKey('440');

        app(AutoSellUseCase::class)->execute();

        Http::assertSent(function ($request) {
            if (! (str_contains($request->url(), '/v1/offers') && $request->method() === 'POST')) {
                return false;
            }
            $body = json_decode($request->body(), true);

            return ($body['product_id'] ?? null) === 440;
        });
    });

    it('lists at max_api when there are no competitors', function () {
        // Sem concorrentes → sellerPrice = 0 → entra pelo teto (max_api)
        fakeGamivoAutoSell();
        insertAutoSellKey('440', ['min_api' => 3.00, 'max_api' => 25.00]);

        app(AutoSellUseCase::class)->execute();

        Http::assertSent(function ($request) {
            if (! (str_contains($request->url(), '/v1/offers') && $request->method() === 'POST')) {
                return false;
            }
            $body = json_decode($request->body(), true);

            return (float) ($body['seller_price'] ?? 0) === 25.00;
        });
    });

    it('does not mark listed_at when key is not confirmed active after upload', function () {
        Http::fake([
            '*/products/*/offers' => Http::response([], 200),
            '*/v1/offers' => Http::response(12345, 200),
            '*/offers/12345/keys/upload' => Http::response(999, 200),
            '*/offers/12345/jobs/999/result' => Http::response('"Done"', 200),
            // Verificação retorna count = 0 — key não encontrada como ativa
            '*/offers/12345/keys/active/0/1*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);

        insertAutoSellKey('440');

        $result = app(AutoSellUseCase::class)->execute();

        expect($result)->toBeEmpty()
            ->and(DB::table('keys')->where('gamivo_id', '440')->value('listed_at'))->toBeNull();
    });

    it('skips listing when the competitive price is below min_api', function () {
        // Concorrente a €1.50, min_api = 3.00 → mercado hostil, não listar
        Http::fake([
            '*/products/*/offers' => Http::response([
                ['id' => 99, 'seller_name' => 'Rival', 'retail_price' => 1.50,
                    'completed_orders' => 1000, 'wholesale_mode' => 0, 'stock_available' => 5,
                    'rating' => 4.5, 'invoicable' => false, 'is_preorder' => false],
            ], 200),
            '*/v1/offers' => Http::response(12345, 200),
            '*/offers/12345/keys/upload' => Http::response(999, 200),
            '*/offers/12345/jobs/999/result' => Http::response('"Done"', 200),
        ]);

        // sellerPrice = 1.50 - PRICE_STEP = ~1.486, calculado pelo IncomeCalculator
        // que para retail < 8 retorna price*(1-0.06)-0.25 ≈ 1.486*0.94-0.25 ≈ 1.15
        // min_api = 3.00 → deve pular
        insertAutoSellKey('440', ['min_api' => 3.00, 'max_api' => 25.00]);

        $result = app(AutoSellUseCase::class)->execute();

        expect($result)->toBeEmpty()
            ->and(DB::table('keys')->where('gamivo_id', '440')->value('listed_at'))->toBeNull();
    });

    // ── Resiliência ───────────────────────────────────────────────────────────

    it('continues listing subsequent keys when one fails', function () {
        // Primeira key: createOffer lança exceção
        // Segunda key: sucesso
        Http::fake([
            '*/products/440/offers' => Http::response([], 200),
            '*/products/730/offers' => Http::response([], 200),
            '*/v1/offers' => Http::sequence()
                ->push('error', 500)
                ->push(12345, 200),
            '*/offers/12345/keys/upload' => Http::response(999, 200),
            '*/offers/12345/jobs/999/result' => Http::response('"Done"', 200),
            '*/offers/12345/keys/active/0/1*' => Http::response(['count' => 1, 'data' => []], 200),
        ]);

        insertAutoSellKey('440');
        insertAutoSellKey('730');

        $result = app(AutoSellUseCase::class)->execute();

        expect($result)->toHaveCount(1)
            ->and(DB::table('keys')->whereNotNull('listed_at')->count())->toBe(1);
    });

    // ── Eligibility rules ─────────────────────────────────────────────────────

    describe('skips a key when', function () {

        it('gamivo_id is null', function () {
            fakeGamivoAutoSell();
            insertAutoSellKey('', ['gamivo_id' => null]);

            $result = app(AutoSellUseCase::class)->execute();

            expect($result)->toBeEmpty();
        });

        it('gamivo_id is an empty string', function () {
            fakeGamivoAutoSell();
            insertAutoSellKey('', ['gamivo_id' => '']);

            $result = app(AutoSellUseCase::class)->execute();

            expect($result)->toBeEmpty();
        });

        it('the key is already listed (listed_at is set)', function () {
            fakeGamivoAutoSell();
            insertAutoSellKey('440', ['listed_at' => now()->subDays(5)->toDateString()]);

            $result = app(AutoSellUseCase::class)->execute();

            expect($result)->toBeEmpty();
        });

        it('the key was already sold (sold_at is set)', function () {
            fakeGamivoAutoSell();
            insertAutoSellKey('440', ['sold_at' => now()->subDays(3)->toDateString()]);

            $result = app(AutoSellUseCase::class)->execute();

            expect($result)->toBeEmpty();
        });

        it('key_code contains "http" (gift link)', function () {
            fakeGamivoAutoSell();
            insertAutoSellKey('440', ['key_code' => 'https://store.steampowered.com/gift/abc']);

            $result = app(AutoSellUseCase::class)->execute();

            expect($result)->toBeEmpty();
        });
    });

    // ── 21-day bundle rule ────────────────────────────────────────────────────

    describe('21-day bundle rule', function () {

        it('skips a key whose game is in a bundle released less than 21 days ago', function () {
            fakeGamivoAutoSell();

            $gamivoId = '440';
            insertAutoSellKey($gamivoId);

            $gameId = DB::table('games')->insertGetId([
                'name' => 'Recent Bundle Game', 'gamivo_id' => $gamivoId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $bundleId = DB::table('bundles')->insertGetId([
                'name' => 'Recent Bundle', 'type' => 'bundle',
                'release_date' => Carbon::now()->subDays(10)->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('bundle_games')->insert([
                'bundle_id' => $bundleId, 'game_id' => $gameId,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $result = app(AutoSellUseCase::class)->execute();

            expect($result)->toBeEmpty();
        });

        it('lists a key whose game is in a bundle released more than 21 days ago', function () {
            fakeGamivoAutoSell();

            $gamivoId = '440';
            insertAutoSellKey($gamivoId);

            $gameId = DB::table('games')->insertGetId([
                'name' => 'Old Bundle Game', 'gamivo_id' => $gamivoId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $bundleId = DB::table('bundles')->insertGetId([
                'name' => 'Old Bundle', 'type' => 'bundle',
                'release_date' => Carbon::now()->subDays(30)->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('bundle_games')->insert([
                'bundle_id' => $bundleId, 'game_id' => $gameId,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $result = app(AutoSellUseCase::class)->execute();

            expect($result)->toHaveCount(1);
        });

        it('skips a key when the bundle was released exactly 20 days ago', function () {
            fakeGamivoAutoSell();

            $gamivoId = '440';
            insertAutoSellKey($gamivoId);

            $gameId = DB::table('games')->insertGetId([
                'name' => '20-Day Game', 'gamivo_id' => $gamivoId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $bundleId = DB::table('bundles')->insertGetId([
                'name' => '20-Day Bundle', 'type' => 'choice',
                'release_date' => Carbon::now()->subDays(20)->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('bundle_games')->insert([
                'bundle_id' => $bundleId, 'game_id' => $gameId,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $result = app(AutoSellUseCase::class)->execute();

            expect($result)->toBeEmpty();
        });
    });
});
