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
|  6. Age override: keys >= OLD_KEY_MONTHS ignoram min_api e têm max_api travado
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
 * sem concorrentes → cria oferta → atualiza preço → upload → key verificada como ativa.
 */
function fakeGamivoAutoSell(): void
{
    Http::fake([
        '*/products/*/offers' => Http::response([], 200),
        '*/v1/offers' => Http::response(12345, 200),
        '*/offers/12345/change-status' => Http::response(12345, 200),
        '*/offers/12345' => Http::response(12345, 200),          // updateOffer (PUT)
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

        DB::table('suppliers')->insert(['id' => 1, 'url' => 'https://steamcommunity.com/id/seed']);

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
            '*/offers/11111/change-status' => Http::response(11111, 200),
            '*/offers/22222/change-status' => Http::response(22222, 200),
            '*/offers/11111' => Http::response(11111, 200),      // updateOffer (PUT) key 1
            '*/offers/22222' => Http::response(22222, 200),      // updateOffer (PUT) key 2
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

    it('sends the correct product in the createOffer request', function () {
        fakeGamivoAutoSell();
        insertAutoSellKey('440');

        app(AutoSellUseCase::class)->execute();

        Http::assertSent(function ($request) {
            if (! (str_contains($request->url(), '/v1/offers') && $request->method() === 'POST')) {
                return false;
            }
            $body = json_decode($request->body(), true);

            return ($body['product'] ?? null) === 440;
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

    it('always sends status 1 in the updateOffer call', function () {
        fakeGamivoAutoSell();
        insertAutoSellKey('440');

        app(AutoSellUseCase::class)->execute();

        Http::assertSent(function ($request) {
            if (! (str_contains($request->url(), '/offers/12345') && $request->method() === 'PUT')
                || str_contains($request->url(), '/change-status')
            ) {
                return false;
            }
            $body = json_decode($request->body(), true);

            return ($body['status'] ?? null) === 1;
        });
    });

    it('does not mark listed_at when key is not confirmed active after upload', function () {
        Http::fake([
            '*/products/*/offers' => Http::response([], 200),
            '*/v1/offers' => Http::response(12345, 200),
            '*/offers/12345' => Http::response(12345, 200),      // updateOffer (PUT)
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

        insertAutoSellKey('440', ['min_api' => 3.00, 'max_api' => 25.00]);

        $result = app(AutoSellUseCase::class)->execute();

        expect($result)->toBeEmpty()
            ->and(DB::table('keys')->where('gamivo_id', '440')->value('listed_at'))->toBeNull();
    });

    // ── min_api é o único portão de margem ────────────────────────────────────

    it('lists a key once the market price clears min_api, with no separate margin check', function () {
        // min_api já embute a margem mínima correta (MinimumMarginPolicy).
        // Concorrente a €2.50 > min_api (2.00) — mesmo com lucro relativo baixo
        // (0.50, bem abaixo dos 60% que o antigo hasMinimumProfitForAutoSell exigiria),
        // não há mais uma segunda checagem de margem: superar o min_api já é suficiente.
        Http::fake([
            '*/products/*/offers' => Http::response([
                ['id' => 99, 'seller_name' => 'Rival', 'retail_price' => 2.50,
                    'completed_orders' => 1000, 'wholesale_mode' => 0, 'stock_available' => 5,
                    'rating' => 4.5, 'invoicable' => false, 'is_preorder' => false],
            ], 200),
            '*/v1/offers' => Http::response(12345, 200),
            '*/offers/12345/change-status' => Http::response(12345, 200),
            '*/offers/12345' => Http::response(12345, 200),      // updateOffer (PUT)
            '*/offers/12345/keys/upload' => Http::response(999, 200),
            '*/offers/12345/jobs/999/result' => Http::response('"Done"', 200),
            '*/offers/12345/keys/active/0/1*' => Http::response(['count' => 1, 'data' => []], 200),
        ]);

        insertAutoSellKey('440', [
            'individual_cost' => 2.00,
            'min_api' => 2.00,
            'max_api' => 20.00,
            'acquired_at' => now()->subMonths(3)->toDateString(),
        ]);

        $result = app(AutoSellUseCase::class)->execute();

        expect($result)->toHaveCount(1)
            ->and(DB::table('keys')->where('gamivo_id', '440')->value('listed_at'))
            ->toBe(now()->toDateString());
    });

    // ── Keys velhas (>= OLD_KEY_MONTHS) — min_api já rebaixado ao FLOOR pela policy ──

    it('lists an old key with a very low market price because its min_api is already at FLOOR', function () {
        // Keys >= OLD_KEY_MONTHS têm o min_api rebaixado ao FLOOR pela MinimumMarginPolicy
        // (persistido pelo RegulateMinApiUseCase, pré-requisito do AutoSell). Aqui simulamos
        // isso com min_api = FLOOR. Concorrente a €1.50 → preço-alvo ~1.15 >= FLOOR → lista.
        // O AutoSell não reavalia a idade: apenas consulta o min_api (fonte única do piso).
        Http::fake([
            '*/products/*/offers' => Http::response([
                ['id' => 99, 'seller_name' => 'Rival', 'retail_price' => 1.50,
                    'completed_orders' => 1000, 'wholesale_mode' => 0, 'stock_available' => 5,
                    'rating' => 4.5, 'invoicable' => false, 'is_preorder' => false],
            ], 200),
            '*/v1/offers' => Http::response(12345, 200),
            '*/offers/12345/change-status' => Http::response(12345, 200),
            '*/offers/12345' => Http::response(12345, 200),      // updateOffer (PUT)
            '*/offers/12345/keys/upload' => Http::response(999, 200),
            '*/offers/12345/jobs/999/result' => Http::response('"Done"', 200),
            '*/offers/12345/keys/active/0/1*' => Http::response(['count' => 1, 'data' => []], 200),
        ]);

        insertAutoSellKey('440', [
            'min_api' => 0.02, // FLOOR — o que a policy grava para uma key >= OLD_KEY_MONTHS
            'max_api' => 20.00,
            'acquired_at' => now()->subMonths(11)->toDateString(),
        ]);

        $result = app(AutoSellUseCase::class)->execute();

        expect($result)->toHaveCount(1)
            ->and(DB::table('keys')->where('gamivo_id', '440')->value('listed_at'))
            ->toBe(now()->toDateString());
    });

    it('skips an old key when its persisted min_api is still high (policy not yet run)', function () {
        // Guard-rail explícito: o AutoSell NÃO reavalia a idade para ignorar o piso. Se o
        // RegulateMinApiUseCase ainda não rebaixou o min_api desta key velha, ela é pulada
        // como qualquer outra abaixo do piso — a idade só vale via o min_api já persistido.
        Http::fake([
            '*/products/*/offers' => Http::response([
                ['id' => 99, 'seller_name' => 'Rival', 'retail_price' => 1.50,
                    'completed_orders' => 1000, 'wholesale_mode' => 0, 'stock_available' => 5,
                    'rating' => 4.5, 'invoicable' => false, 'is_preorder' => false],
            ], 200),
            '*/v1/offers' => Http::response(12345, 200),
            '*/offers/12345/keys/upload' => Http::response(999, 200),
        ]);

        insertAutoSellKey('440', [
            'min_api' => 10.00, // ainda não rebaixado pela policy
            'max_api' => 20.00,
            'acquired_at' => now()->subMonths(11)->toDateString(),
        ]);

        $result = app(AutoSellUseCase::class)->execute();

        expect($result)->toBeEmpty()
            ->and(DB::table('keys')->where('gamivo_id', '440')->value('listed_at'))->toBeNull();
    });

    it('locks max_api to the seller price after listing a key acquired >= OLD_KEY_MONTHS ago, leaving min_api untouched', function () {
        // sem concorrentes → sellerPrice = max_api original = 20.00
        // key >= OLD_KEY_MONTHS → max_api travado em 20.00 (sellerPrice), impedindo o
        // UpdateOffersUseCase de subir o preço depois. min_api não é alterado
        // aqui — RegulateMinApiUseCase é quem mantém esse valor correto.
        fakeGamivoAutoSell();
        insertAutoSellKey('440', [
            'min_api' => 2.00,
            'max_api' => 20.00,
            'acquired_at' => now()->subMonths(11)->toDateString(),
        ]);

        app(AutoSellUseCase::class)->execute();

        $key = DB::table('keys')->where('gamivo_id', '440')->first();
        expect((float) $key->min_api)->toBe(2.00)
            ->and((float) $key->max_api)->toBe(20.00);
    });

    it('does not update min_api or max_api for keys acquired less than OLD_KEY_MONTHS ago', function () {
        fakeGamivoAutoSell();
        insertAutoSellKey('440', [
            'min_api' => 2.00,
            'max_api' => 20.00,
            'acquired_at' => now()->subMonths(5)->toDateString(),
        ]);

        app(AutoSellUseCase::class)->execute();

        $key = DB::table('keys')->where('gamivo_id', '440')->first();
        expect((float) $key->min_api)->toBe(2.00)
            ->and((float) $key->max_api)->toBe(20.00);
    });

    // ── Reativação de oferta existente ───────────────────────────────────────────

    it('applies the new calculated price even when createOffer falls back to reactivation', function () {
        // Cenário: oferta existia inativa → createOffer retorna 400 "Offer already exists [12345]"
        // → changeOfferStatus apenas reativa com o preço antigo
        // → updateOffer deve ser chamado para corrigir o preço
        //
        // Sem concorrentes → sellerPrice = max_api = 15.00.
        // Verificamos que updateOffer (PUT) é chamado com seller_price = 15.00.
        Http::fake([
            '*/products/*/offers' => Http::response([], 200),
            // createOffer retorna 400 com offerId no texto — simula oferta já existente inativa
            '*/v1/offers' => Http::response(['reason' => 'Offer already exists [12345]'], 400),
            '*/offers/12345/change-status' => Http::response(12345, 200),
            '*/offers/12345' => Http::response(12345, 200),      // updateOffer (PUT) — deve receber preço correto
            '*/offers/12345/keys/upload' => Http::response(999, 200),
            '*/offers/12345/keys/active/0/1*' => Http::response(['count' => 1, 'data' => []], 200),
        ]);

        insertAutoSellKey('440', ['min_api' => 3.00, 'max_api' => 15.00]);

        app(AutoSellUseCase::class)->execute();

        // Garante que updateOffer foi chamado com o preço correto (não o preço antigo da oferta)
        Http::assertSent(function ($request) {
            if (! (str_contains($request->url(), '/offers/12345') && $request->method() === 'PUT')
                || str_contains($request->url(), '/change-status')
            ) {
                return false;
            }
            $body = json_decode($request->body(), true);

            return (float) ($body['seller_price'] ?? 0) === 15.00;
        });
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
            '*/offers/12345/change-status' => Http::response(12345, 200),
            '*/offers/12345' => Http::response(12345, 200),      // updateOffer (PUT) segunda key
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

    // ── Agrupamento por gamivo_id (FIFO: governante = menor id) ───────────────

    describe('grouping by gamivo_id', function () {

        it('creates a single offer and uploads all keys of the same gamivo_id at once', function () {
            fakeGamivoAutoSell();
            insertAutoSellKey('440', ['key_code' => 'AAA-1']);
            insertAutoSellKey('440', ['key_code' => 'BBB-2']);
            insertAutoSellKey('440', ['key_code' => 'CCC-3']);

            $result = app(AutoSellUseCase::class)->execute();

            // Todas as 3 keys listadas
            expect($result)->toHaveCount(3)
                ->and(DB::table('keys')->whereNotNull('listed_at')->count())->toBe(3);

            // Uma única oferta criada (POST no endpoint exato /v1/offers, não /offers/{id}/...)
            $createOfferCalls = 0;
            Http::assertSent(function ($request) use (&$createOfferCalls) {
                if (str_ends_with($request->url(), '/v1/offers') && $request->method() === 'POST') {
                    $createOfferCalls++;
                }

                return true;
            });
            expect($createOfferCalls)->toBe(1);

            // Um único uploadKeys com os 3 códigos no mesmo body
            Http::assertSent(function ($request) {
                if (! str_contains($request->url(), '/keys/upload')) {
                    return false;
                }
                $body = json_decode($request->body(), true);

                return $body['keys'] === ['AAA-1', 'BBB-2', 'CCC-3'];
            });
        });

        it('uploads key codes in id ASC order (oldest first)', function () {
            fakeGamivoAutoSell();
            // Inseridas fora de ordem de código, mas os ids crescem na ordem de inserção
            $first = insertAutoSellKey('440', ['key_code' => 'OLDEST']);
            $second = insertAutoSellKey('440', ['key_code' => 'MIDDLE']);
            $third = insertAutoSellKey('440', ['key_code' => 'NEWEST']);

            expect($first)->toBeLessThan($second)->and($second)->toBeLessThan($third);

            app(AutoSellUseCase::class)->execute();

            Http::assertSent(function ($request) {
                if (! str_contains($request->url(), '/keys/upload')) {
                    return false;
                }
                $body = json_decode($request->body(), true);

                // Ordem FIFO preservada: governante (menor id) primeiro
                return $body['keys'] === ['OLDEST', 'MIDDLE', 'NEWEST'];
            });
        });

        it('prices the group by the governing key (oldest id), not other keys in the group', function () {
            // Sem concorrentes → sellerPrice = max_api. A governante (menor id) tem
            // max_api = 15.00; a segunda key tem max_api = 99.00. O preço deve seguir a governante.
            fakeGamivoAutoSell();
            insertAutoSellKey('440', ['key_code' => 'GOV', 'min_api' => 3.00, 'max_api' => 15.00]);
            insertAutoSellKey('440', ['key_code' => 'OTHER', 'min_api' => 3.00, 'max_api' => 99.00]);

            app(AutoSellUseCase::class)->execute();

            Http::assertSent(function ($request) {
                if (! (str_contains($request->url(), '/v1/offers') && $request->method() === 'POST')) {
                    return false;
                }
                $body = json_decode($request->body(), true);

                return (float) ($body['seller_price'] ?? 0) === 15.00;
            });
        });

        it('lists a key whose own min_api the market covers, and skips a group-mate below its own min_api', function () {
            // Concorrente a 2.50 → preço-alvo de mercado ~2.09. A decisão é POR KEY:
            //  - A (menor id, "mais velha por id" mas nova): min_api 10.00 → 2.09 < 10 → NÃO lista.
            //  - B (mais nova): min_api 2.00 → 2.09 >= 2 → LISTA e vira a governante (única aprovada).
            // Prova: a key mais nova com o mercado cobrindo o SEU min_api é listada e governa,
            // mesmo havendo uma key de id menor no grupo.
            Http::fake([
                '*/products/*/offers' => Http::response([
                    ['id' => 99, 'seller_name' => 'Rival', 'retail_price' => 2.50,
                        'completed_orders' => 1000, 'wholesale_mode' => 0, 'stock_available' => 5,
                        'rating' => 4.5, 'invoicable' => false, 'is_preorder' => false],
                ], 200),
                '*/v1/offers' => Http::response(12345, 200),
                '*/offers/12345/change-status' => Http::response(12345, 200),
                '*/offers/12345' => Http::response(12345, 200),
                '*/offers/12345/keys/upload' => Http::response(999, 200),
                '*/offers/12345/keys/active/0/1*' => Http::response(['count' => 1, 'data' => []], 200),
            ]);

            $expensiveId = insertAutoSellKey('440', [
                'key_code' => 'A-HIGH-MIN', 'min_api' => 10.00, 'max_api' => 20.00,
                'acquired_at' => now()->subMonths(2)->toDateString(),
            ]);
            $cheapId = insertAutoSellKey('440', [
                'key_code' => 'B-LOW-MIN', 'min_api' => 2.00, 'max_api' => 20.00,
                'acquired_at' => now()->subMonths(2)->toDateString(),
            ]);

            $result = app(AutoSellUseCase::class)->execute();

            expect($result)->toBe([$cheapId])
                ->and(DB::table('keys')->where('id', $expensiveId)->value('listed_at'))->toBeNull();

            // Só a key aprovada foi enviada no upload
            Http::assertSent(function ($request) {
                if (! str_contains($request->url(), '/keys/upload')) {
                    return false;
                }
                $body = json_decode($request->body(), true);

                return $body['keys'] === ['B-LOW-MIN'];
            });
        });

        it('lists an old key (min_api at FLOOR) even when a newer group-mate is skipped below its own min_api', function () {
            // Mercado ~1.15 (concorrente 1.50). A decisão é POR KEY, cada uma pelo SEU min_api:
            //  - NEW (menor id, 2 meses): min_api 10.00 → 1.15 < 10 → NÃO lista.
            //  - OLD (11 meses): min_api já rebaixado ao FLOOR pela policy → 1.15 >= FLOOR → LISTA,
            //    e vira a governante (única aprovada).
            // Prova: a key nova pulada não bloqueia a velha; cada uma é avaliada pelo próprio piso.
            Http::fake([
                '*/products/*/offers' => Http::response([
                    ['id' => 99, 'seller_name' => 'Rival', 'retail_price' => 1.50,
                        'completed_orders' => 1000, 'wholesale_mode' => 0, 'stock_available' => 5,
                        'rating' => 4.5, 'invoicable' => false, 'is_preorder' => false],
                ], 200),
                '*/v1/offers' => Http::response(12345, 200),
                '*/offers/12345/change-status' => Http::response(12345, 200),
                '*/offers/12345' => Http::response(12345, 200),
                '*/offers/12345/keys/upload' => Http::response(999, 200),
                '*/offers/12345/keys/active/0/1*' => Http::response(['count' => 1, 'data' => []], 200),
            ]);

            $newId = insertAutoSellKey('440', [
                'key_code' => 'NEW-SKIP', 'min_api' => 10.00, 'max_api' => 20.00,
                'acquired_at' => now()->subMonths(2)->toDateString(),
            ]);
            $oldId = insertAutoSellKey('440', [
                'key_code' => 'OLD-LIST', 'min_api' => 0.02, 'max_api' => 20.00,
                'acquired_at' => now()->subMonths(11)->toDateString(),
            ]);

            $result = app(AutoSellUseCase::class)->execute();

            expect($result)->toBe([$oldId])
                ->and(DB::table('keys')->where('id', $oldId)->value('listed_at'))->toBe(now()->toDateString())
                ->and(DB::table('keys')->where('id', $newId)->value('listed_at'))->toBeNull();

            Http::assertSent(function ($request) {
                if (! str_contains($request->url(), '/keys/upload')) {
                    return false;
                }
                $body = json_decode($request->body(), true);

                return $body['keys'] === ['OLD-LIST'];
            });
        });

        it('lists both keys when the market clears each own min_api (old governing key at FLOOR)', function () {
            // Governante velha (11m, min_api FLOOR) + key nova cujo min_api (2.00) o mercado cobre.
            // Sem concorrentes → preço = max_api da governante = 20.00; ambas listam.
            fakeGamivoAutoSell();

            insertAutoSellKey('440', [
                'key_code' => 'GOV-OLD', 'min_api' => 0.02, 'max_api' => 20.00,
                'acquired_at' => now()->subMonths(11)->toDateString(),
            ]);
            insertAutoSellKey('440', [
                'key_code' => 'NEW-OK', 'min_api' => 2.00, 'max_api' => 20.00,
                'acquired_at' => now()->subMonths(2)->toDateString(),
            ]);

            $result = app(AutoSellUseCase::class)->execute();

            expect($result)->toHaveCount(2);
        });

        it('locks max_api only on the individually old keys of a mixed group', function () {
            // Sem concorrentes → sellerPrice = max_api da governante (velha) = 20.00.
            // A governante (velha) tem o max_api travado em 20.00 (= sellerPrice); a key nova
            // do grupo mantém seu max_api original (99.00) — a trava é por-key, não do grupo.
            fakeGamivoAutoSell();

            $oldId = insertAutoSellKey('440', [
                'key_code' => 'GOV-OLD', 'min_api' => 2.00, 'max_api' => 20.00,
                'acquired_at' => now()->subMonths(11)->toDateString(),
            ]);
            $newId = insertAutoSellKey('440', [
                'key_code' => 'NEW', 'min_api' => 2.00, 'max_api' => 99.00,
                'acquired_at' => now()->subMonths(2)->toDateString(),
            ]);

            app(AutoSellUseCase::class)->execute();

            expect((float) DB::table('keys')->where('id', $oldId)->value('max_api'))->toBe(20.00)
                ->and((float) DB::table('keys')->where('id', $newId)->value('max_api'))->toBe(99.00);
        });

        it('skips every key when none is old and the market is below each own min_api', function () {
            // Duas keys jovens do mesmo produto, min_api 3.00; mercado ~1.15 → cada uma reprovada
            // no seu próprio min_api e nenhuma é velha → grupo inteiro pulado.
            Http::fake([
                '*/products/*/offers' => Http::response([
                    ['id' => 99, 'seller_name' => 'Rival', 'retail_price' => 1.50,
                        'completed_orders' => 1000, 'wholesale_mode' => 0, 'stock_available' => 5,
                        'rating' => 4.5, 'invoicable' => false, 'is_preorder' => false],
                ], 200),
            ]);

            insertAutoSellKey('440', ['key_code' => 'K1', 'min_api' => 3.00, 'max_api' => 25.00]);
            insertAutoSellKey('440', ['key_code' => 'K2', 'min_api' => 3.00, 'max_api' => 25.00]);

            $result = app(AutoSellUseCase::class)->execute();

            expect($result)->toBeEmpty()
                ->and(DB::table('keys')->whereNotNull('listed_at')->count())->toBe(0);
        });

        it('marks only the confirmed keys and leaves the unconfirmed ones eligible', function () {
            // 2 keys enviadas; a verificação confirma só a primeira (count=1 depois count=0)
            Http::fake([
                '*/products/*/offers' => Http::response([], 200),
                '*/v1/offers' => Http::response(12345, 200),
                '*/offers/12345/change-status' => Http::response(12345, 200),
                '*/offers/12345' => Http::response(12345, 200),
                '*/offers/12345/keys/upload' => Http::response(999, 200),
                // Filtro por código: CONF-1 aparece (count=1), CONF-2 nunca (count=0)
                '*/offers/12345/keys/active/0/1*' => function ($request) {
                    $filters = json_decode($request->data()['filters'] ?? '{}', true);
                    $code = $filters['keys'][0] ?? '';

                    return Http::response(['count' => $code === 'CONF-1' ? 1 : 0, 'data' => []], 200);
                },
            ]);

            $confirmedId = insertAutoSellKey('440', ['key_code' => 'CONF-1']);
            $unconfirmedId = insertAutoSellKey('440', ['key_code' => 'CONF-2']);

            $result = app(AutoSellUseCase::class)->execute();

            expect($result)->toBe([$confirmedId])
                ->and(DB::table('keys')->where('id', $confirmedId)->value('listed_at'))->toBe(now()->toDateString())
                ->and(DB::table('keys')->where('id', $unconfirmedId)->value('listed_at'))->toBeNull();
        });
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
