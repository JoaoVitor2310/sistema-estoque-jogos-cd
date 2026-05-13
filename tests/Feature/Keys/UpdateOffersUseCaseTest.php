<?php

/*
|--------------------------------------------------------------------------
| UpdateOffersUseCase — feature tests
|--------------------------------------------------------------------------
|
| Cobre o fluxo completo de reprecificação:
|   - Produtos com preço a atualizar disparam PUT /offers
|   - Produtos com noAction não disparam PUT
|   - Produtos ignorados (1767, 42931) são pulados
|   - Clamp min_api/max_api ajusta o preço final
|   - Erro em um produto não interrompe os demais
|
| Todos os requests Gamivo são interceptados via Http::fake().
| Taxas carregadas do banco via Fee (cache desabilitado em APP_ENV=testing).
|
*/

use App\Models\Fee;
use App\UseCases\Marketplaces\Gamivo\UpdateOffersUseCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedFees(): void
{
    Fee::upsert([
        ['name' => 'gamivo_percent_low', 'preco' => 0.060],
        ['name' => 'gamivo_fixed_low', 'preco' => 0.250],
        ['name' => 'gamivo_percent_high', 'preco' => 0.080],
        ['name' => 'gamivo_fixed_high', 'preco' => 0.400],
    ], uniqueBy: ['name'], update: ['preco']);
}

function insertKeyWithMinMax(int $gamivoId, float $minApi, float $maxApi): void
{
    DB::table('suppliers')->insertOrIgnore([
        'id' => 99,
        'supplier_url' => 'https://steamcommunity.com/id/update-offers-test',
    ]);

    DB::table('keys')->insertOrIgnore([
        'game_name' => "Game {$gamivoId}",
        'gamivo_id' => (string) $gamivoId,
        'key_code' => "KEY-UPDATE-{$gamivoId}",
        'market_price' => 5.00,
        'individual_cost' => 2.00,
        'min_api' => $minApi,
        'max_api' => $maxApi,
        'purchase_profit_percent' => 25.00,
        'supplier_url' => 'https://steamcommunity.com/id/update-offers-test',
        'supplier_id' => 99,
        'claim_type' => 'Nenhuma',
        'key_format' => 'RK',
        'sell_platform' => 'Gamivo',
        'listed_at' => now(),
        'sold_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function fakeActiveOffers(array $productIds): array
{
    return array_map(fn ($id) => ['product_id' => $id, 'status' => 1], $productIds);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('UpdateOffersUseCase', function () {

    beforeEach(function () {
        seedFees();
    });

    // ── Fluxo principal ───────────────────────────────────────────────────────

    it('updates the price and returns the product id when the algorithm signals updatePrice', function () {
        // Produto 111: não somos o mais barato → deve atualizar
        // sellerPrice ≈ 2.56 (CompetitorA 3.00 - 0.014 → seller sem taxa)
        // Padrões ordenados do mais específico para o mais geral — o broad '*/offers*'
        // também casaria com PUT /offers/51, então o específico deve vir primeiro.
        Http::fake([
            '*/api/public/v1/offers/51*' => Http::response(51, 200),
            '*/api/public/v1/products/111/offers' => Http::response([
                ['id' => 50, 'seller_name' => 'CompetitorA', 'retail_price' => 3.00, 'completed_orders' => 5000, 'wholesale_mode' => 0],
                ['id' => 51, 'seller_name' => 'CarcaDeals', 'retail_price' => 3.30, 'completed_orders' => 1000, 'wholesale_mode' => 0],
            ], 200),
            '*/api/public/v1/offers*' => Http::response(fakeActiveOffers([111]), 200),
        ]);

        $updated = app(UpdateOffersUseCase::class)->execute();

        expect($updated)->toContain(111);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/offers/51'));
    });

    it('skips the PUT request when the algorithm returns noAction', function () {
        // Produto 222: somos o único vendedor → no_competitors → noAction
        Http::fake([
            '*/api/public/v1/offers*' => Http::response(fakeActiveOffers([222]), 200),
            '*/api/public/v1/products/222/offers' => Http::response([
                ['id' => 60, 'seller_name' => 'CarcaDeals', 'retail_price' => 3.00, 'completed_orders' => 1000, 'wholesale_mode' => 0],
            ], 200),
        ]);

        $updated = app(UpdateOffersUseCase::class)->execute();

        expect($updated)->not->toContain(222);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/offers/60'));
    });

    // ── Produtos ignorados ────────────────────────────────────────────────────

    it('skips product 1767 (Random Game) without requesting its offers', function () {
        Http::fake([
            '*/api/public/v1/offers*' => Http::response(fakeActiveOffers([1767]), 200),
        ]);

        $updated = app(UpdateOffersUseCase::class)->execute();

        expect($updated)->toBeEmpty();
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/products/1767/offers'));
    });

    it('skips product 42931 (Spotify) without requesting its offers', function () {
        Http::fake([
            '*/api/public/v1/offers*' => Http::response(fakeActiveOffers([42931]), 200),
        ]);

        $updated = app(UpdateOffersUseCase::class)->execute();

        expect($updated)->toBeEmpty();
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/products/42931/offers'));
    });

    // ── Clamp min/max ─────────────────────────────────────────────────────────

    it('clamps the price to min_api when the calculated price is below it', function () {
        // sellerPrice calculado ≈ 2.56; min_api = 3.00 → deve enviar 3.00
        insertKeyWithMinMax(333, minApi: 3.00, maxApi: 10.00);

        Http::fake([
            '*/api/public/v1/offers/71*' => Http::response(71, 200),
            '*/api/public/v1/products/333/offers' => Http::response([
                ['id' => 70, 'seller_name' => 'CompetitorA', 'retail_price' => 3.00, 'completed_orders' => 5000, 'wholesale_mode' => 0],
                ['id' => 71, 'seller_name' => 'CarcaDeals', 'retail_price' => 3.30, 'completed_orders' => 1000, 'wholesale_mode' => 0],
            ], 200),
            '*/api/public/v1/offers*' => Http::response(fakeActiveOffers([333]), 200),
        ]);

        app(UpdateOffersUseCase::class)->execute();

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), '/offers/71')) {
                return false;
            }

            // Preço deve ser clamped ao min_api (3.00), não o calculado (≈2.56)
            return $req->data()['seller_price'] === 3.00;
        });
    });

    it('clamps the price to max_api when the calculated price exceeds it', function () {
        // sellerPrice calculado ≈ 2.56; max_api = 2.00 → deve enviar 2.00
        insertKeyWithMinMax(444, minApi: 0.50, maxApi: 2.00);

        Http::fake([
            '*/api/public/v1/offers/81*' => Http::response(81, 200),
            '*/api/public/v1/products/444/offers' => Http::response([
                ['id' => 80, 'seller_name' => 'CompetitorA', 'retail_price' => 3.00, 'completed_orders' => 5000, 'wholesale_mode' => 0],
                ['id' => 81, 'seller_name' => 'CarcaDeals', 'retail_price' => 3.30, 'completed_orders' => 1000, 'wholesale_mode' => 0],
            ], 200),
            '*/api/public/v1/offers*' => Http::response(fakeActiveOffers([444]), 200),
        ]);

        app(UpdateOffersUseCase::class)->execute();

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), '/offers/81')) {
                return false;
            }

            return $req->data()['seller_price'] === 2.00;
        });
    });

    // ── Log de atualização ────────────────────────────────────────────────────

    it('logs game_name, old_retail and new_retail for each updated product', function () {
        // CarcaDeals é o mais barato (8.79) → handleWeAreLowest
        // targetRetail = 9.85 - 0.014 = 9.836 → new_retail = 9.84
        // old_retail = 8.79 (nosso retail atual)
        insertKeyWithMinMax(888, minApi: 0.50, maxApi: 30.00);

        Http::fake([
            '*/api/public/v1/offers/201*' => Http::response(201, 200),
            '*/api/public/v1/products/888/offers' => Http::response([
                ['id' => 200, 'seller_name' => 'CompetitorA', 'retail_price' => 9.85, 'completed_orders' => 5000, 'wholesale_mode' => 0],
                ['id' => 201, 'seller_name' => 'CarcaDeals', 'retail_price' => 8.79, 'completed_orders' => 1000, 'wholesale_mode' => 0],
            ], 200),
            '*/api/public/v1/offers*' => Http::response(fakeActiveOffers([888]), 200),
        ]);

        $captured = [];
        Log::listen(function (\Illuminate\Log\Events\MessageLogged $event) use (&$captured) {
            if ($event->message === 'UpdateOffersUseCase') {
                $captured = $event->context;
            }
        });

        app(UpdateOffersUseCase::class)->execute();

        $details = $captured['updated_details'][0] ?? null;

        expect($details)->not->toBeNull()
            ->and($details['game_name'])->toBe('Game 888')
            ->and($details['old_retail'])->toBe(8.79)
            ->and($details['new_retail'])->toEqualWithDelta(9.84, 0.01);
    });

    // ── Resiliência ───────────────────────────────────────────────────────────

    it('continues processing remaining products when one throws an exception', function () {
        // Produto 555 vai lançar exceção; produto 666 deve ser processado normalmente
        Http::fake([
            '*/api/public/v1/offers/91*' => Http::response(91, 200),
            '*/api/public/v1/products/555/offers' => Http::response(['reason' => 'Server Error'], 500),
            '*/api/public/v1/products/666/offers' => Http::response([
                ['id' => 90, 'seller_name' => 'CompetitorA', 'retail_price' => 3.00, 'completed_orders' => 5000, 'wholesale_mode' => 0],
                ['id' => 91, 'seller_name' => 'CarcaDeals', 'retail_price' => 3.30, 'completed_orders' => 1000, 'wholesale_mode' => 0],
            ], 200),
            '*/api/public/v1/offers*' => Http::response(fakeActiveOffers([555, 666]), 200),
        ]);

        $updated = app(UpdateOffersUseCase::class)->execute();

        // 555 falhou, 666 deve ter sido atualizado
        expect($updated)->not->toContain(555)
            ->and($updated)->toContain(666);
    });

    it('returns an empty array when there are no active offers', function () {
        Http::fake([
            '*/api/public/v1/offers*' => Http::response([], 200),
        ]);

        $updated = app(UpdateOffersUseCase::class)->execute();

        expect($updated)->toBeEmpty();
    });

    // ── Modos WeAreLowest / WeAreNotLowest ────────────────────────────────────

    it('skips products where we are not lowest when mode is WeAreLowest', function () {
        // Produto 501: CarcaDeals é o mais caro → weAreLowest = false → deve ser pulado
        Http::fake([
            '*/api/public/v1/offers/111*' => Http::response(111, 200),
            '*/api/public/v1/products/501/offers' => Http::response([
                ['id' => 110, 'seller_name' => 'CompetitorA', 'retail_price' => 3.00, 'completed_orders' => 5000, 'wholesale_mode' => 0],
                ['id' => 111, 'seller_name' => 'CarcaDeals', 'retail_price' => 3.30, 'completed_orders' => 1000, 'wholesale_mode' => 0],
            ], 200),
            '*/api/public/v1/offers*' => Http::response(fakeActiveOffers([501]), 200),
        ]);

        $updated = app(UpdateOffersUseCase::class)->execute(\App\Domain\Enums\OffersUpdateMode::WeAreLowest);

        expect($updated)->not->toContain(501);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/offers/111'));
    });

    it('processes products where we are lowest when mode is WeAreLowest', function () {
        // Produto 502: CarcaDeals é o mais barato → weAreLowest = true → deve processar
        // (offer id=120 pertence ao CarcaDeals → PUT vai para /offers/120)
        insertKeyWithMinMax(502, minApi: 0.50, maxApi: 30.00);

        Http::fake([
            '*/api/public/v1/offers/120*' => Http::response(120, 200),
            '*/api/public/v1/products/502/offers' => Http::response([
                ['id' => 120, 'seller_name' => 'CarcaDeals', 'retail_price' => 8.79, 'completed_orders' => 1000, 'wholesale_mode' => 0],
                ['id' => 121, 'seller_name' => 'CompetitorA', 'retail_price' => 9.85, 'completed_orders' => 5000, 'wholesale_mode' => 0],
            ], 200),
            '*/api/public/v1/offers*' => Http::response(fakeActiveOffers([502]), 200),
        ]);

        $updated = app(UpdateOffersUseCase::class)->execute(\App\Domain\Enums\OffersUpdateMode::WeAreLowest);

        expect($updated)->toContain(502);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/offers/120'));
    });

    it('skips products where we are lowest when mode is WeAreNotLowest', function () {
        // Produto 503: CarcaDeals é o mais barato → weAreLowest = true → deve ser pulado
        Http::fake([
            '*/api/public/v1/products/503/offers' => Http::response([
                ['id' => 130, 'seller_name' => 'CarcaDeals', 'retail_price' => 8.79, 'completed_orders' => 1000, 'wholesale_mode' => 0],
                ['id' => 131, 'seller_name' => 'CompetitorA', 'retail_price' => 9.85, 'completed_orders' => 5000, 'wholesale_mode' => 0],
            ], 200),
            '*/api/public/v1/offers*' => Http::response(fakeActiveOffers([503]), 200),
        ]);

        $updated = app(UpdateOffersUseCase::class)->execute(\App\Domain\Enums\OffersUpdateMode::WeAreNotLowest);

        expect($updated)->not->toContain(503);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/offers/130'));
    });

    it('processes products where we are not lowest when mode is WeAreNotLowest', function () {
        // Produto 504: CarcaDeals é o mais caro → weAreLowest = false → deve processar
        Http::fake([
            '*/api/public/v1/offers/141*' => Http::response(141, 200),
            '*/api/public/v1/products/504/offers' => Http::response([
                ['id' => 140, 'seller_name' => 'CompetitorA', 'retail_price' => 3.00, 'completed_orders' => 5000, 'wholesale_mode' => 0],
                ['id' => 141, 'seller_name' => 'CarcaDeals', 'retail_price' => 3.30, 'completed_orders' => 1000, 'wholesale_mode' => 0],
            ], 200),
            '*/api/public/v1/offers*' => Http::response(fakeActiveOffers([504]), 200),
        ]);

        $updated = app(UpdateOffersUseCase::class)->execute(\App\Domain\Enums\OffersUpdateMode::WeAreNotLowest);

        expect($updated)->toContain(504);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/offers/141'));
    });

    // ── Wholesale ─────────────────────────────────────────────────────────────

    it('sends tier prices when the offer has wholesale mode 1', function () {
        // sellerPrice ≈ 2.56; tier = round(2.56 / 1.035, 2) = 2.47
        Http::fake([
            '*/api/public/v1/offers/101*' => Http::response(101, 200),
            '*/api/public/v1/products/777/offers' => Http::response([
                ['id' => 100, 'seller_name' => 'CompetitorA', 'retail_price' => 3.00, 'completed_orders' => 5000, 'wholesale_mode' => 0],
                ['id' => 101, 'seller_name' => 'CarcaDeals', 'retail_price' => 3.30, 'completed_orders' => 1000, 'wholesale_mode' => 1],
            ], 200),
            '*/api/public/v1/offers*' => Http::response(fakeActiveOffers([777]), 200),
        ]);

        app(UpdateOffersUseCase::class)->execute();

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), '/offers/101')) {
                return false;
            }
            $data = $req->data();

            return $data['wholesale_mode'] === 1
                && isset($data['tier_one_seller_price'])
                && isset($data['tier_two_seller_price'])
                && $data['tier_one_seller_price'] < $data['seller_price'];
        });
    });
});
