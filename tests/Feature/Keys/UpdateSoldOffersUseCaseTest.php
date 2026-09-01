<?php

/*
|--------------------------------------------------------------------------
| UpdateSoldOffersUseCase — characterization tests
|--------------------------------------------------------------------------
|
| Cobre o recebimento de dados de venda da API Gamivo e a atualização
| das keys correspondentes no banco.
|
| Regras documentadas em PRODUCT.md:
|   - Uma key pode ser vendida com lucro, zero lucro ou prejuízo.
|   - Keys já vendidas (sold_price preenchido) não devem ser sobreescritas.
|   - Keys não encontradas são silenciosamente ignoradas.
|
*/

use App\Domain\Enums\OrderPayoutAttribution;
use App\UseCases\Marketplaces\Gamivo\UpdateSoldOffersUseCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedSoldOffersFks(): void
{
    DB::table('fees')->insert([
        ['name' => 'gamivo_percent_low', 'preco' => 0.060, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_low',       'preco' => 0.250, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_percent_high', 'preco' => 0.080, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_high',       'preco' => 0.400, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('assets')->insert([
        ['name' => 'TF2', 'price_euro' => 2.0, 'price_dollar' => 2.2, 'price_brl' => 10.0, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('suppliers')->insert(['id' => 1, 'url' => 'https://steamcommunity.com/id/seed']);
}

/**
 * Insere uma key ainda não vendida com um custo individual conhecido.
 * O gamivo_id é o product_id da Gamivo — é por ele que o histórico de vendas
 * casa cada linha do pedido com a key entregue.
 */
function insertUnsoldKey(string $keyCode, float $individualCost = 2.00, ?string $gamivoId = null): void
{
    DB::table('keys')->insert([
        'game_name' => 'Test Game',
        'gamivo_id' => $gamivoId ?? 'gam-'.uniqid(),
        'key_code' => $keyCode,
        'market_price' => 5.00,
        'individual_cost' => $individualCost,
        'min_api' => 1.00,
        'max_api' => 10.00,
        'purchase_profit_percent' => 25.00,
        'supplier_url' => 'https://steamcommunity.com/id/test',
        'supplier_id' => 1,
        'claim_type' => 'Nenhuma',
        'key_format' => 'RK',
        'sell_platform' => 'Gamivo',
        'listed_at' => now()->subDays(10)->toDateString(),
        'sold_at' => null,
        'sold_price' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('UpdateSoldOffersUseCase', function () {

    beforeEach(function () {
        seedSoldOffersFks();
        Cache::flush();
    });

    // ── Happy path ────────────────────────────────────────────────────────────

    it('marks the key as sold with sold_at and sold_price', function () {
        insertUnsoldKey('SOLD-KEY-001');

        app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['SOLD-KEY-001'], 'profit' => 5.00, 'saleDate' => '2024-06-01'],
        ]);

        $row = DB::table('keys')->where('key_code', 'SOLD-KEY-001')->first();

        expect($row->sold_at)->toBe('2024-06-01')
            ->and((float) $row->sold_price)->toBe(5.00);
    });

    it('calculates sale_profit as salePrice minus individualCost', function () {
        // sale_profit = 5.00 - 2.00 = 3.00
        insertUnsoldKey('PROFIT-KEY-001', individualCost: 2.00);

        app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['PROFIT-KEY-001'], 'profit' => 5.00, 'saleDate' => '2024-06-01'],
        ]);

        $row = DB::table('keys')->where('key_code', 'PROFIT-KEY-001')->first();

        expect((float) $row->sale_profit)->toEqualWithDelta(3.00, 0.001);
    });

    it('calculates sale_profit_percent relative to the individual cost', function () {
        // sale_profit_percent = (3.00 / 2.00) × 100 = 150%
        insertUnsoldKey('PROFIT-KEY-002', individualCost: 2.00);

        app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['PROFIT-KEY-002'], 'profit' => 5.00, 'saleDate' => '2024-06-01'],
        ]);

        $row = DB::table('keys')->where('key_code', 'PROFIT-KEY-002')->first();

        expect((float) $row->sale_profit_percent)->toEqualWithDelta(150.0, 0.01);
    });

    it('records zero profit when sold exactly at cost', function () {
        insertUnsoldKey('ZERO-KEY-001', individualCost: 3.00);

        app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['ZERO-KEY-001'], 'profit' => 3.00, 'saleDate' => '2024-06-01'],
        ]);

        $row = DB::table('keys')->where('key_code', 'ZERO-KEY-001')->first();

        expect((float) $row->sale_profit)->toEqualWithDelta(0.0, 0.001);
    });

    it('records a loss (negative sale_profit) when sold below cost', function () {
        // Cenário real: jogo desvalorizou após bundle, vendido abaixo do custo
        // sale_profit = 1.00 - 3.00 = -2.00
        insertUnsoldKey('LOSS-KEY-001', individualCost: 3.00);

        app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['LOSS-KEY-001'], 'profit' => 1.00, 'saleDate' => '2024-06-01'],
        ]);

        $row = DB::table('keys')->where('key_code', 'LOSS-KEY-001')->first();

        expect((float) $row->sale_profit)->toEqualWithDelta(-2.00, 0.001);
    });

    it('returns an empty failed array when all keys were updated successfully', function () {
        insertUnsoldKey('CLEAN-KEY-001');

        $result = app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['CLEAN-KEY-001'], 'profit' => 5.00, 'saleDate' => '2024-06-01'],
        ]);

        expect($result['failed'])->toBeEmpty();
    });

    // ── updatedKeys ───────────────────────────────────────────────────────────

    it('includes key_code and game_name in updatedKeys for each updated key', function () {
        insertUnsoldKey('TRACK-KEY-001');

        $result = app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['TRACK-KEY-001'], 'profit' => 5.00, 'saleDate' => '2024-06-01'],
        ]);

        expect($result['updatedKeys'])->toHaveCount(1)
            ->and($result['updatedKeys'][0]['key_code'])->toBe('TRACK-KEY-001')
            ->and($result['updatedKeys'][0]['game_name'])->toBe('Test Game');
    });

    it('does not include skipped keys in updatedKeys', function () {
        $result = app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['GHOST-KEY-NOT-IN-DB'], 'profit' => 5.00, 'saleDate' => '2024-06-01'],
        ]);

        expect($result['updatedKeys'])->toBeEmpty();
    });

    // ── Multiple keys in one game ─────────────────────────────────────────────

    it('updates all keys listed in the same game object', function () {
        insertUnsoldKey('MULTI-KEY-001');
        insertUnsoldKey('MULTI-KEY-002');

        app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['MULTI-KEY-001', 'MULTI-KEY-002'], 'profit' => 5.00, 'saleDate' => '2024-06-01'],
        ]);

        $updated = DB::table('keys')
            ->whereIn('key_code', ['MULTI-KEY-001', 'MULTI-KEY-002'])
            ->whereNotNull('sold_at')
            ->count();

        expect($updated)->toBe(2);
    });

    it('processes multiple game objects in a single execute call', function () {
        insertUnsoldKey('GAME-A-KEY-001', individualCost: 2.00);
        insertUnsoldKey('GAME-B-KEY-001', individualCost: 4.00);

        app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['GAME-A-KEY-001'], 'profit' => 5.00, 'saleDate' => '2024-06-01'],
            ['keys' => ['GAME-B-KEY-001'], 'profit' => 8.00, 'saleDate' => '2024-06-01'],
        ]);

        $soldCount = DB::table('keys')
            ->whereIn('key_code', ['GAME-A-KEY-001', 'GAME-B-KEY-001'])
            ->whereNotNull('sold_at')
            ->count();

        expect($soldCount)->toBe(2);
    });

    // ── Exclusion rules ───────────────────────────────────────────────────────

    it('silently skips a key not found in the database', function () {
        // Não insere nenhuma key — execute deve retornar vazio sem exceção
        $result = app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['GHOST-KEY-999'], 'profit' => 5.00, 'saleDate' => '2024-06-01'],
        ]);

        expect($result['failed'])->toBeEmpty();
    });

    it('does not overwrite a key that was already sold', function () {
        // Key já possui sold_price = 3.00 (venda anterior)
        DB::table('keys')->insert([
            'game_name' => 'Already Sold Game',
            'key_code' => 'ALREADY-SOLD-001',
            'market_price' => 5.00,
            'individual_cost' => 2.00,
            'min_api' => 1.00,
            'max_api' => 10.00,
            'purchase_profit_percent' => 25.00,
            'supplier_url' => 'https://steamcommunity.com/id/test',
            'supplier_id' => 1,
            'claim_type' => 'Nenhuma',
            'key_format' => 'RK',
            'sell_platform' => 'Gamivo',
            'listed_at' => now()->subDays(15)->toDateString(),
            'sold_at' => now()->subDays(5)->toDateString(),
            'sold_price' => 3.00, // Já foi vendida
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(UpdateSoldOffersUseCase::class)->execute([
            ['keys' => ['ALREADY-SOLD-001'], 'profit' => 99.00, 'saleDate' => '2024-06-01'],
        ]);

        $row = DB::table('keys')->where('key_code', 'ALREADY-SOLD-001')->first();

        // sold_price deve permanecer 3.00 — não sobreescrito
        expect((float) $row->sold_price)->toBe(3.00);
    });
});

// ── executeFromGamivo ─────────────────────────────────────────────────────────

/**
 * Monta uma linha do histórico de vendas da Gamivo.
 * A API devolve uma linha por oferta vendida — várias linhas podem compartilhar o order_id.
 */
function gamivoSaleLine(
    string $orderId,
    string $productId,
    float $profit,
    float $sellerTax = 0.0,
    int $quantity = 1,
    string $createdAt = '2025-05-06UTC10:00:000',
): array {
    return [
        'order_id' => $orderId,
        'product_id' => $productId,
        'quantity' => $quantity,
        'profit' => $profit,
        'seller_tax' => $sellerTax,
        'created_at' => $createdAt,
    ];
}

/**
 * Monta a resposta do order-details a partir de um mapa offer_id => key_codes.
 *
 * @param  array<string, string[]>  $keysByOffer
 */
function gamivoOrderDetails(string $orderId, array $keysByOffer): array
{
    $keys = [];

    foreach ($keysByOffer as $offerId => $keyCodes) {
        $keys[(string) $offerId] = [
            'keys' => array_map(fn (string $code) => ['type' => 'TEXT', 'key' => $code], $keyCodes),
            'rating' => '-',
        ];
    }

    return ['id' => $orderId, 'keys' => $keys];
}

describe('UpdateSoldOffersUseCase::executeFromGamivo', function () {

    beforeEach(function () {
        seedSoldOffersFks();
        Cache::flush();
    });

    it('fetches sales from Gamivo API and marks the key as sold', function () {
        insertUnsoldKey('GAMIVO-KEY-001', gamivoId: '31000');

        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response([
                'count' => 1,
                'data' => [gamivoSaleLine('order-uuid-abc', '31000', profit: 2.75)],
            ], 200),
            '*/accounts/sales/order-details/order-uuid-abc*' => Http::response(
                gamivoOrderDetails('order-uuid-abc', ['9001' => ['GAMIVO-KEY-001']]), 200
            ),
        ]);

        app(UpdateSoldOffersUseCase::class)->executeFromGamivo();

        $row = DB::table('keys')->where('key_code', 'GAMIVO-KEY-001')->first();

        // profit = 2.75 + 0.00 - 0.01 = 2.74
        expect($row->sold_at)->toBe('2025-05-06')
            ->and((float) $row->sold_price)->toEqualWithDelta(2.74, 0.001);
    });

    it('adds seller_tax to the profit before recording', function () {
        insertUnsoldKey('GAMIVO-KEY-TAX', gamivoId: '31001');

        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response([
                'count' => 1,
                'data' => [gamivoSaleLine('order-tax-001', '31001', profit: 2.00, sellerTax: 0.50)],
            ], 200),
            '*/accounts/sales/order-details/order-tax-001*' => Http::response(
                gamivoOrderDetails('order-tax-001', ['9002' => ['GAMIVO-KEY-TAX']]), 200
            ),
        ]);

        app(UpdateSoldOffersUseCase::class)->executeFromGamivo();

        $row = DB::table('keys')->where('key_code', 'GAMIVO-KEY-TAX')->first();

        // profit = 2.00 + 0.50 - 0.01 = 2.49
        expect((float) $row->sold_price)->toEqualWithDelta(2.49, 0.001);
    });

    // ── Pedido com várias ofertas ─────────────────────────────────────────────

    it('gives each offer of a multi-offer order its own profit', function () {
        // Pedido real (Nekopara Vol. 0/1/2 no mesmo order_id): cada volume vendeu por
        // um preço diferente. A ordem das linhas aqui não é a devolvida pela API — é
        // de propósito, porque no bug antigo a primeira linha processada vencia
        insertUnsoldKey('NEKO-VOL1', individualCost: 1.34, gamivoId: '31082');
        insertUnsoldKey('NEKO-VOL2', individualCost: 1.00, gamivoId: '31083');
        insertUnsoldKey('NEKO-VOL0', individualCost: 0.50, gamivoId: '31081');

        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response([
                'count' => 3,
                'data' => [
                    gamivoSaleLine('order-neko', '31083', profit: 1.15, sellerTax: 0.30),
                    gamivoSaleLine('order-neko', '31082', profit: 1.90, sellerTax: 0.46),
                    gamivoSaleLine('order-neko', '31081', profit: 0.63, sellerTax: 0.19),
                ],
            ], 200),
            '*/accounts/sales/order-details/order-neko*' => Http::response(
                gamivoOrderDetails('order-neko', [
                    '9001' => ['NEKO-VOL1'],
                    '9002' => ['NEKO-VOL2'],
                    '9003' => ['NEKO-VOL0'],
                ]), 200
            ),
        ]);

        app(UpdateSoldOffersUseCase::class)->executeFromGamivo();

        $sold = DB::table('keys')->whereIn('key_code', ['NEKO-VOL1', 'NEKO-VOL2', 'NEKO-VOL0'])
            ->pluck('sold_price', 'key_code');

        // Cada key recebe o líquido da sua própria linha, não a média do pedido (1.54)
        // nem o valor da primeira linha processada (0.48, o bug corrigido aqui)
        expect((float) $sold['NEKO-VOL1'])->toEqualWithDelta(2.35, 0.001)
            ->and((float) $sold['NEKO-VOL2'])->toEqualWithDelta(1.45, 0.001)
            ->and((float) $sold['NEKO-VOL0'])->toEqualWithDelta(0.82, 0.001);
    });

    it('charges the mediation fee once per order, not once per offer line', function () {
        insertUnsoldKey('FEE-VOL1', gamivoId: '32001');
        insertUnsoldKey('FEE-VOL2', gamivoId: '32002');
        insertUnsoldKey('FEE-VOL3', gamivoId: '32003');

        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response([
                'count' => 3,
                'data' => [
                    gamivoSaleLine('order-fee', '32001', profit: 1.90, sellerTax: 0.46),
                    gamivoSaleLine('order-fee', '32002', profit: 1.15, sellerTax: 0.30),
                    gamivoSaleLine('order-fee', '32003', profit: 0.63, sellerTax: 0.19),
                ],
            ], 200),
            '*/accounts/sales/order-details/order-fee*' => Http::response(
                gamivoOrderDetails('order-fee', [
                    '9001' => ['FEE-VOL1'],
                    '9002' => ['FEE-VOL2'],
                    '9003' => ['FEE-VOL3'],
                ]), 200
            ),
        ]);

        app(UpdateSoldOffersUseCase::class)->executeFromGamivo();

        $total = DB::table('keys')->whereIn('key_code', ['FEE-VOL1', 'FEE-VOL2', 'FEE-VOL3'])
            ->sum('sold_price');

        // bruto 2.36 + 1.45 + 0.82 = 4.63, menos uma única mediação de 0.01
        expect((float) $total)->toEqualWithDelta(4.62, 0.001);
    });

    it('fetches the order details once per order, not once per offer line', function () {
        insertUnsoldKey('ONCE-VOL1', gamivoId: '33001');
        insertUnsoldKey('ONCE-VOL2', gamivoId: '33002');

        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response([
                'count' => 2,
                'data' => [
                    gamivoSaleLine('order-once', '33001', profit: 2.00),
                    gamivoSaleLine('order-once', '33002', profit: 1.00),
                ],
            ], 200),
            '*/accounts/sales/order-details/order-once*' => Http::response(
                gamivoOrderDetails('order-once', [
                    '9001' => ['ONCE-VOL1'],
                    '9002' => ['ONCE-VOL2'],
                ]), 200
            ),
        ]);

        app(UpdateSoldOffersUseCase::class)->executeFromGamivo();

        // 1 página de histórico + 1 order-details para as duas linhas do pedido
        Http::assertSentCount(2);
    });

    it('divides the line profit equally when the same offer sold more than one unit', function () {
        insertUnsoldKey('MULTI-GAMIVO-001', gamivoId: '34000');
        insertUnsoldKey('MULTI-GAMIVO-002', gamivoId: '34000');

        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response([
                'count' => 1,
                'data' => [gamivoSaleLine('order-multi-001', '34000', profit: 4.00, quantity: 2)],
            ], 200),
            '*/accounts/sales/order-details/order-multi-001*' => Http::response(
                gamivoOrderDetails('order-multi-001', [
                    '9003' => ['MULTI-GAMIVO-001', 'MULTI-GAMIVO-002'],
                ]), 200
            ),
        ]);

        app(UpdateSoldOffersUseCase::class)->executeFromGamivo();

        $sold = DB::table('keys')->whereIn('key_code', ['MULTI-GAMIVO-001', 'MULTI-GAMIVO-002'])
            ->pluck('sold_price', 'key_code');

        // líquido 4.00 - 0.01 = 3.99 dividido entre as duas unidades: 2.00 e 1.99,
        // com o centavo ímpar indo para uma delas em vez de se perder
        expect((float) $sold['MULTI-GAMIVO-001'] + (float) $sold['MULTI-GAMIVO-002'])
            ->toEqualWithDelta(3.99, 0.001)
            ->and((float) $sold['MULTI-GAMIVO-001'])->toEqualWithDelta(2.00, 0.011)
            ->and((float) $sold['MULTI-GAMIVO-002'])->toEqualWithDelta(2.00, 0.011);
    });

    it('groups lines of the same order that arrive on different pages', function () {
        insertUnsoldKey('PAGED-VOL1', gamivoId: '35001');
        insertUnsoldKey('PAGED-VOL2', gamivoId: '35002');

        // A primeira página enche com linhas de outro pedido, empurrando a segunda
        // linha do nosso pedido para a página seguinte
        $filler = array_fill(0, 24, gamivoSaleLine('order-filler', '99999', profit: 1.00));

        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response([
                'count' => 25,
                'data' => array_merge(
                    [gamivoSaleLine('order-paged', '35001', profit: 1.90, sellerTax: 0.46)],
                    $filler,
                ),
            ], 200),
            '*/accounts/sales/history/25/25*' => Http::response([
                'count' => 1,
                'data' => [gamivoSaleLine('order-paged', '35002', profit: 1.15, sellerTax: 0.30)],
            ], 200),
            '*/accounts/sales/order-details/order-paged*' => Http::response(
                gamivoOrderDetails('order-paged', [
                    '9001' => ['PAGED-VOL1'],
                    '9002' => ['PAGED-VOL2'],
                ]), 200
            ),
            '*/accounts/sales/order-details/order-filler*' => Http::response('', 404),
        ]);

        app(UpdateSoldOffersUseCase::class)->executeFromGamivo();

        $sold = DB::table('keys')->whereIn('key_code', ['PAGED-VOL1', 'PAGED-VOL2'])
            ->pluck('sold_price', 'key_code');

        // bruto 2.36 + 1.45 = 3.81, menos uma mediação = 3.80
        expect((float) $sold['PAGED-VOL1'])->toEqualWithDelta(2.35, 0.001)
            ->and((float) $sold['PAGED-VOL2'])->toEqualWithDelta(1.45, 0.001);
    });

    // ── Fallback do rateio ────────────────────────────────────────────────────

    it('keeps the exact value of the lines that matched when another key is unknown', function () {
        // Só a segunda key está fora da base: isso não pode custar à primeira o
        // valor da própria oferta — o casamento é linha a linha, não tudo-ou-nada
        insertUnsoldKey('KNOWN-KEY', gamivoId: '36001');

        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response([
                'count' => 2,
                'data' => [
                    gamivoSaleLine('order-partial', '36001', profit: 3.00),
                    gamivoSaleLine('order-partial', '36002', profit: 1.00),
                ],
            ], 200),
            '*/accounts/sales/order-details/order-partial*' => Http::response(
                gamivoOrderDetails('order-partial', [
                    '9001' => ['KNOWN-KEY'],
                    '9002' => ['UNKNOWN-KEY'],
                ]), 200
            ),
        ]);

        app(UpdateSoldOffersUseCase::class)->executeFromGamivo();

        $row = DB::table('keys')->where('key_code', 'KNOWN-KEY')->first();

        // 3.00 da sua linha menos a parte proporcional da mediação, não a média 2.00
        expect((float) $row->sold_price)->toEqualWithDelta(2.99, 0.001);
    });

    it('gives the leftover gross to the keys that no line claimed', function () {
        // A linha de 37001 diz ter vendido 2 unidades e o pedido só entregou 1:
        // ela fica sem par, e a key que sobrou recebe o bruto que ficou órfão
        insertUnsoldKey('SHORT-KEY-A', gamivoId: '37001');
        insertUnsoldKey('SHORT-KEY-B', gamivoId: '37002');

        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response([
                'count' => 2,
                'data' => [
                    gamivoSaleLine('order-short', '37001', profit: 3.00, quantity: 2),
                    gamivoSaleLine('order-short', '37002', profit: 1.00),
                ],
            ], 200),
            '*/accounts/sales/order-details/order-short*' => Http::response(
                gamivoOrderDetails('order-short', [
                    '9001' => ['SHORT-KEY-A'],
                    '9002' => ['SHORT-KEY-B'],
                ]), 200
            ),
        ]);

        app(UpdateSoldOffersUseCase::class)->executeFromGamivo();

        $sold = DB::table('keys')->whereIn('key_code', ['SHORT-KEY-A', 'SHORT-KEY-B'])
            ->pluck('sold_price', 'key_code');

        // A linha que casou mantém o valor exato; nenhuma key fica de fora e o
        // líquido do pedido continua inteiro
        expect((float) $sold['SHORT-KEY-B'])->toEqualWithDelta(1.00, 0.001)
            ->and((float) $sold['SHORT-KEY-A'] + (float) $sold['SHORT-KEY-B'])
            ->toEqualWithDelta(3.99, 0.001);
    });

    it('falls back to an equal split when a line has no key left to receive its value', function () {
        // O pedido entregou uma key só, mas o histórico traz duas linhas: o bruto da
        // segunda não tem onde pousar, então o pedido inteiro volta ao rateio igual
        // — errado por key, mas preserva o total recebido
        insertUnsoldKey('LONE-KEY', gamivoId: '40001');

        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response([
                'count' => 2,
                'data' => [
                    gamivoSaleLine('order-lone', '40001', profit: 3.00),
                    gamivoSaleLine('order-lone', '40002', profit: 1.00),
                ],
            ], 200),
            '*/accounts/sales/order-details/order-lone*' => Http::response(
                gamivoOrderDetails('order-lone', ['9001' => ['LONE-KEY']]), 200
            ),
        ]);

        app(UpdateSoldOffersUseCase::class)->executeFromGamivo();

        $row = DB::table('keys')->where('key_code', 'LONE-KEY')->first();

        expect((float) $row->sold_price)->toEqualWithDelta(3.99, 0.001);
    });

    it('ignores a line repeated by the offset pagination', function () {
        // A paginação por offset relê linhas quando uma venda nova entra no meio da
        // varredura. Caso real: um pedido chegou com a mesma linha duas vezes e o
        // bruto duplicado foi parar numa key que nem era daquela oferta
        insertUnsoldKey('PAGE-KEY-A', individualCost: 0.20, gamivoId: '31090');
        insertUnsoldKey('PAGE-KEY-B', individualCost: 0.20, gamivoId: '31091');

        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response([
                'count' => 3,
                'data' => [
                    gamivoSaleLine('order-dup', '31090', profit: 0.90),
                    gamivoSaleLine('order-dup', '31090', profit: 0.90),
                    gamivoSaleLine('order-dup', '31091', profit: 0.54),
                ],
            ], 200),
            '*/accounts/sales/order-details/order-dup*' => Http::response(
                gamivoOrderDetails('order-dup', ['9010' => ['PAGE-KEY-A'], '9011' => ['PAGE-KEY-B']]), 200
            ),
        ]);

        app(UpdateSoldOffersUseCase::class)->executeFromGamivo();

        $a = (float) DB::table('keys')->where('key_code', 'PAGE-KEY-A')->value('sold_price');
        $b = (float) DB::table('keys')->where('key_code', 'PAGE-KEY-B')->value('sold_price');

        // 0,90 + 0,54 − 0,01 = 1,43 — a linha repetida não pode inflar o pedido
        expect($a)->toEqualWithDelta(0.89, 0.011)
            ->and($b)->toEqualWithDelta(0.54, 0.011)
            ->and($a + $b)->toEqualWithDelta(1.43, 0.001);
    });

    it('counts each order by how its payout was attributed', function () {
        insertUnsoldKey('LOG-KEY', gamivoId: '41001');

        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response([
                'count' => 1,
                'data' => [gamivoSaleLine('order-log', '41001', profit: 3.00)],
            ], 200),
            '*/accounts/sales/order-details/order-log*' => Http::response(
                gamivoOrderDetails('order-log', ['9001' => ['LOG-KEY']]), 200
            ),
        ]);

        Log::shouldReceive('channel')->with('schedulers')->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) {
            return $message === 'UpdateSoldOffersUseCase'
                && $context['orders_by_attribution'][OrderPayoutAttribution::Matched->value] === 1
                && $context['orders_by_attribution'][OrderPayoutAttribution::EqualSplit->value] === 0;
        });

        app(UpdateSoldOffersUseCase::class)->executeFromGamivo();
    });

    // ── Bordas ────────────────────────────────────────────────────────────────

    it('extracts the sale date from the non-standard created_at format', function () {
        insertUnsoldKey('GAMIVO-DATE-KEY', gamivoId: '38001');

        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response([
                'count' => 1,
                'data' => [gamivoSaleLine('order-date-001', '38001', profit: 3.00, createdAt: '2025-04-13UTC17:44:480')],
            ], 200),
            '*/accounts/sales/order-details/order-date-001*' => Http::response(
                gamivoOrderDetails('order-date-001', ['9004' => ['GAMIVO-DATE-KEY']]), 200
            ),
        ]);

        app(UpdateSoldOffersUseCase::class)->executeFromGamivo();

        $row = DB::table('keys')->where('key_code', 'GAMIVO-DATE-KEY')->first();

        expect($row->sold_at)->toBe('2025-04-13');
    });

    it('returns an empty array and logs when there are no sales in the period', function () {
        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);

        $result = app(UpdateSoldOffersUseCase::class)->executeFromGamivo();

        expect($result)->toBeEmpty();
    });

    it('skips a sale when order-details returns null', function () {
        insertUnsoldKey('GAMIVO-SKIP-KEY', gamivoId: '39001');

        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response([
                'count' => 1,
                'data' => [gamivoSaleLine('order-missing-001', '39001', profit: 3.00)],
            ], 200),
            // 404 → getSaleOrderDetails retorna null
            '*/accounts/sales/order-details/order-missing-001*' => Http::response('', 404),
        ]);

        app(UpdateSoldOffersUseCase::class)->executeFromGamivo();

        // Key não deve ter sido atualizada
        $row = DB::table('keys')->where('key_code', 'GAMIVO-SKIP-KEY')->first();
        expect($row->sold_at)->toBeNull();
    });
});
