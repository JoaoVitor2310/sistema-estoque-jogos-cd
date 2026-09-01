<?php

/*
|--------------------------------------------------------------------------
| gamivo:backfill-sold-prices — feature tests
|--------------------------------------------------------------------------
|
| O cron nunca sobrescreve key já vendida, então todo sold_price gravado
| errado antes da correção do rateio por linha é permanente. Este comando é
| o único caminho de conserto — e por isso o que ele NÃO grava importa tanto
| quanto o que grava. Todos os requests Gamivo são interceptados via Http::fake().
|
*/

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedBackfillFks(): void
{
    DB::table('fees')->insert([
        ['name' => 'gamivo_percent_low', 'preco' => 0.060, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_low', 'preco' => 0.250, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_percent_high', 'preco' => 0.080, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_high', 'preco' => 0.400, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('suppliers')->insert(['id' => 1, 'url' => 'https://steamcommunity.com/id/backfill']);
}

/**
 * Insere uma key já baixada — o cenário que o backfill existe para consertar.
 * soldPrice null representa a venda que nunca foi processada.
 */
function insertSoldKey(string $keyCode, string $gamivoId, ?float $soldPrice, float $individualCost = 1.00): void
{
    DB::table('keys')->insert([
        'game_name' => 'Backfill Game',
        'gamivo_id' => $gamivoId,
        'key_code' => $keyCode,
        'market_price' => 5.00,
        'individual_cost' => $individualCost,
        'min_api' => 1.00,
        'max_api' => 10.00,
        'purchase_profit_percent' => 25.00,
        'supplier_url' => 'https://steamcommunity.com/id/backfill',
        'supplier_id' => 1,
        'claim_type' => 'Nenhuma',
        'key_format' => 'RK',
        'sell_platform' => 'Gamivo',
        'listed_at' => now()->subDays(20)->toDateString(),
        'sold_at' => $soldPrice === null ? null : '2025-01-01',
        'sold_price' => $soldPrice,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function backfillSaleLine(string $orderId, string $productId, float $profit, float $sellerTax = 0.0, int $quantity = 1): array
{
    return [
        'order_id' => $orderId,
        'product_id' => $productId,
        'quantity' => $quantity,
        'profit' => $profit,
        'seller_tax' => $sellerTax,
        'created_at' => '2026-07-28UTC17:13:360',
    ];
}

/**
 * @param  array<string, string[]>  $keysByOffer
 */
function backfillOrderDetails(string $orderId, array $keysByOffer): array
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

/**
 * O pedido real ab6c09d8: duas ofertas, gravadas com 0,11 cada pelo bug do
 * rateio quando o payout foi 0,64 (0,43 de profit + 0,22 de imposto − 0,01).
 */
function fakeTwoOfferOrder(): void
{
    Http::fake([
        '*/accounts/sales/history/0/25*' => Http::response([
            'count' => 2,
            'data' => [
                backfillSaleLine('order-two', '186626', profit: 0.30, sellerTax: 0.13),
                backfillSaleLine('order-two', '163729', profit: 0.13, sellerTax: 0.09),
            ],
        ], 200),
        '*/accounts/sales/order-details/order-two*' => Http::response(
            backfillOrderDetails('order-two', ['7001' => ['CUBIC-KEY'], '7002' => ['STEAM-KEY']]), 200
        ),
        '*' => Http::response([], 599),
    ]);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('gamivo:backfill-sold-prices', function () {

    beforeEach(function () {
        seedBackfillFks();

        // A trilha de auditoria é gravada em toda passada, inclusive dry-run:
        // sem o fake, a suíte suja storage/app/diagnostics do ambiente real
        Storage::fake('local');
    });

    it('leaves the database untouched without the apply flag', function () {
        insertSoldKey('CUBIC-KEY', '186626', soldPrice: 0.11);
        insertSoldKey('STEAM-KEY', '163729', soldPrice: 0.11);

        fakeTwoOfferOrder();

        $this->artisan('gamivo:backfill-sold-prices')->assertSuccessful();

        expect((float) DB::table('keys')->where('key_code', 'CUBIC-KEY')->value('sold_price'))
            ->toEqualWithDelta(0.11, 0.001)
            ->and((float) DB::table('keys')->where('key_code', 'STEAM-KEY')->value('sold_price'))
            ->toEqualWithDelta(0.11, 0.001);
    });

    it('rewrites each key with the payout of its own offer', function () {
        insertSoldKey('CUBIC-KEY', '186626', soldPrice: 0.11);
        insertSoldKey('STEAM-KEY', '163729', soldPrice: 0.11);

        fakeTwoOfferOrder();

        $this->artisan('gamivo:backfill-sold-prices --apply')
            ->expectsConfirmation('Gravar as 2 correção(ões) acima?', 'yes')
            ->assertSuccessful();

        // 0,43 + 0,22 − 0,01 = 0,64, repartido pelo líquido de cada oferta
        $cubic = (float) DB::table('keys')->where('key_code', 'CUBIC-KEY')->value('sold_price');
        $steam = (float) DB::table('keys')->where('key_code', 'STEAM-KEY')->value('sold_price');

        expect($cubic)->toEqualWithDelta(0.43, 0.011)
            ->and($steam)->toEqualWithDelta(0.21, 0.011)
            ->and($cubic + $steam)->toEqualWithDelta(0.64, 0.001);
    });

    it('recalculates sale_profit from the corrected payout', function () {
        insertSoldKey('CUBIC-KEY', '186626', soldPrice: 0.11, individualCost: 0.22);
        insertSoldKey('STEAM-KEY', '163729', soldPrice: 0.11, individualCost: 0.05);

        fakeTwoOfferOrder();

        $this->artisan('gamivo:backfill-sold-prices --apply')
            ->expectsConfirmation('Gravar as 2 correção(ões) acima?', 'yes')
            ->assertSuccessful();

        $row = DB::table('keys')->where('key_code', 'CUBIC-KEY')->first();

        // deixa de ser prejuízo: 0,43 − 0,22 = 0,21
        expect((float) $row->sale_profit)->toEqualWithDelta(0.21, 0.011);
    });

    it('aborts without writing when the confirmation is declined', function () {
        insertSoldKey('CUBIC-KEY', '186626', soldPrice: 0.11);
        insertSoldKey('STEAM-KEY', '163729', soldPrice: 0.11);

        fakeTwoOfferOrder();

        $this->artisan('gamivo:backfill-sold-prices --apply')
            ->expectsConfirmation('Gravar as 2 correção(ões) acima?', 'no')
            ->assertSuccessful();

        expect((float) DB::table('keys')->where('key_code', 'CUBIC-KEY')->value('sold_price'))
            ->toEqualWithDelta(0.11, 0.001);
    });

    it('keeps a sale that already holds the right value', function () {
        insertSoldKey('SINGLE-KEY', '31000', soldPrice: 2.74);

        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response([
                'count' => 1,
                'data' => [backfillSaleLine('order-single', '31000', profit: 2.75)],
            ], 200),
            '*/accounts/sales/order-details/order-single*' => Http::response(
                backfillOrderDetails('order-single', ['8001' => ['SINGLE-KEY']]), 200
            ),
            '*' => Http::response([], 599),
        ]);

        $this->artisan('gamivo:backfill-sold-prices --apply')
            ->doesntExpectOutputToContain('SINGLE-KEY')
            ->assertSuccessful();

        expect((float) DB::table('keys')->where('key_code', 'SINGLE-KEY')->value('sold_price'))
            ->toEqualWithDelta(2.74, 0.001);
    });

    it('refuses to touch an order whose payout was split equally', function () {
        // A key entregue não está na base: o recálculo cai no rateio igual, que é
        // chute — trocar um valor errado por outro chutado não é conserto
        insertSoldKey('LONE-KEY', '186626', soldPrice: 0.11);

        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response([
                'count' => 2,
                'data' => [
                    backfillSaleLine('order-split', '186626', profit: 0.30, sellerTax: 0.13),
                    backfillSaleLine('order-split', '999999', profit: 0.13, sellerTax: 0.09),
                ],
            ], 200),
            '*/accounts/sales/order-details/order-split*' => Http::response(
                backfillOrderDetails('order-split', ['7001' => ['LONE-KEY']]), 200
            ),
            '*' => Http::response([], 599),
        ]);

        $this->artisan('gamivo:backfill-sold-prices --apply')->assertSuccessful();

        expect((float) DB::table('keys')->where('key_code', 'LONE-KEY')->value('sold_price'))
            ->toEqualWithDelta(0.11, 0.001);
    });

    it('ignores a difference within the rounding tolerance', function () {
        insertSoldKey('SINGLE-KEY', '31000', soldPrice: 2.73);

        Http::fake([
            '*/accounts/sales/history/0/25*' => Http::response([
                'count' => 1,
                'data' => [backfillSaleLine('order-single', '31000', profit: 2.75)],
            ], 200),
            '*/accounts/sales/order-details/order-single*' => Http::response(
                backfillOrderDetails('order-single', ['8001' => ['SINGLE-KEY']]), 200
            ),
            '*' => Http::response([], 599),
        ]);

        // 2,74 calculado contra 2,73 gravado: um centavo é ruído do maior resto
        $this->artisan('gamivo:backfill-sold-prices --apply')->assertSuccessful();

        expect((float) DB::table('keys')->where('key_code', 'SINGLE-KEY')->value('sold_price'))
            ->toEqualWithDelta(2.73, 0.001);
    });

    it('preserves a refund recorded by hand instead of overwriting it', function () {
        // Reembolso ao cliente: a key voltou como prejuízo da taxa de €1 e do custo.
        // Nenhum payout de venda real é negativo, então o valor só pode ser manual
        insertSoldKey('CUBIC-KEY', '186626', soldPrice: -1.00);
        insertSoldKey('STEAM-KEY', '163729', soldPrice: 0.11);

        fakeTwoOfferOrder();

        $this->artisan('gamivo:backfill-sold-prices --apply')
            ->expectsConfirmation('Gravar as 1 correção(ões) acima?', 'yes')
            ->assertSuccessful();

        expect((float) DB::table('keys')->where('key_code', 'CUBIC-KEY')->value('sold_price'))
            ->toEqualWithDelta(-1.00, 0.001);
    });

    it('writes the payout of a sale that never got one', function () {
        // Venda que passou da janela de 30 dias do cron sem ser processada:
        // sold_price nulo é ausência de baixa, não reembolso de valor zero
        insertSoldKey('CUBIC-KEY', '186626', soldPrice: null);
        insertSoldKey('STEAM-KEY', '163729', soldPrice: null);

        fakeTwoOfferOrder();

        $this->artisan('gamivo:backfill-sold-prices --apply')
            ->expectsConfirmation('Gravar as 2 correção(ões) acima?', 'yes')
            ->assertSuccessful();

        $cubic = (float) DB::table('keys')->where('key_code', 'CUBIC-KEY')->value('sold_price');
        $steam = (float) DB::table('keys')->where('key_code', 'STEAM-KEY')->value('sold_price');

        expect($cubic + $steam)->toEqualWithDelta(0.64, 0.001);
    });

    it('preserves a refund that was zeroed against the key cost', function () {
        // O fornecedor devolveu a key e a taxa: a venda foi lançada como lucro zero
        // contra o próprio custo, e o payout da Gamivo já não vale mais
        insertSoldKey('CUBIC-KEY', '186626', soldPrice: 0.22, individualCost: 0.22);
        insertSoldKey('STEAM-KEY', '163729', soldPrice: 0.11);

        DB::table('keys')->where('key_code', 'CUBIC-KEY')->update(['sale_profit' => 0.00]);
        // valores distintos entre as irmãs: não é digital do rateio

        fakeTwoOfferOrder();

        $this->artisan('gamivo:backfill-sold-prices --apply')
            ->expectsConfirmation('Gravar as 1 correção(ões) acima?', 'yes')
            ->assertSuccessful();

        expect((float) DB::table('keys')->where('key_code', 'CUBIC-KEY')->value('sold_price'))
            ->toEqualWithDelta(0.22, 0.001);
    });

    it('still corrects a sale that lands on the cost with a profit of its own', function () {
        // Mesmo valor do custo, mas com lucro registrado: é venda de verdade que
        // por acaso rendeu perto do custo, não venda desfeita
        insertSoldKey('CUBIC-KEY', '186626', soldPrice: 0.22, individualCost: 0.22);
        insertSoldKey('STEAM-KEY', '163729', soldPrice: 0.11);

        DB::table('keys')->where('key_code', 'CUBIC-KEY')->update(['sale_profit' => 0.05]);

        fakeTwoOfferOrder();

        $this->artisan('gamivo:backfill-sold-prices --apply')
            ->expectsConfirmation('Gravar as 2 correção(ões) acima?', 'yes')
            ->assertSuccessful();

        expect((float) DB::table('keys')->where('key_code', 'CUBIC-KEY')->value('sold_price'))
            ->toEqualWithDelta(0.42, 0.011);
    });

    it('leaves out an order named by the except option', function () {
        insertSoldKey('CUBIC-KEY', '186626', soldPrice: 0.11);
        insertSoldKey('STEAM-KEY', '163729', soldPrice: 0.11);

        fakeTwoOfferOrder();

        $this->artisan('gamivo:backfill-sold-prices --apply --except=order-two')
            ->assertSuccessful();

        expect((float) DB::table('keys')->where('key_code', 'CUBIC-KEY')->value('sold_price'))
            ->toEqualWithDelta(0.11, 0.001);
    });

    it('corrects a key whose wrong split happened to land on its own cost', function () {
        // Caso real (pedido ab6c09d8): o rateio gravou 0,11 nas duas keys, e numa
        // delas o custo também é 0,11 — o lucro deu zero por acaso, não por reembolso.
        // O que separa os dois é o valor repetido entre keys do mesmo pedido
        insertSoldKey('CUBIC-KEY', '186626', soldPrice: 0.11, individualCost: 0.22);
        insertSoldKey('STEAM-KEY', '163729', soldPrice: 0.11, individualCost: 0.11);

        DB::table('keys')->where('key_code', 'STEAM-KEY')->update(['sale_profit' => 0.00]);

        fakeTwoOfferOrder();

        $this->artisan('gamivo:backfill-sold-prices --apply')
            ->expectsConfirmation('Gravar as 2 correção(ões) acima?', 'yes')
            ->assertSuccessful();

        expect((float) DB::table('keys')->where('key_code', 'STEAM-KEY')->value('sold_price'))
            ->toEqualWithDelta(0.22, 0.011);
    });

    it('preserves a refund where only the one-euro fee was lost', function () {
        // O fornecedor devolveu a key mas não a taxa: sobra exatamente €1 de prejuízo
        // e o custo volta inteiro. Caso real: Sker Ritual, custo 1,01, vendido 0,01
        insertSoldKey('CUBIC-KEY', '186626', soldPrice: 0.22, individualCost: 1.22);
        insertSoldKey('STEAM-KEY', '163729', soldPrice: 0.11);

        DB::table('keys')->where('key_code', 'CUBIC-KEY')->update(['sale_profit' => -1.00]);

        fakeTwoOfferOrder();

        $this->artisan('gamivo:backfill-sold-prices --apply')
            ->expectsConfirmation('Gravar as 1 correção(ões) acima?', 'yes')
            ->assertSuccessful();

        expect((float) DB::table('keys')->where('key_code', 'CUBIC-KEY')->value('sold_price'))
            ->toEqualWithDelta(0.22, 0.001);
    });

    it('still corrects a key whose loss of one euro is not the refund fee', function () {
        // Mesmo prejuízo de €1, mas o custo não bate: é venda de verdade no vermelho
        insertSoldKey('CUBIC-KEY', '186626', soldPrice: 0.22, individualCost: 3.00);
        insertSoldKey('STEAM-KEY', '163729', soldPrice: 0.11);

        DB::table('keys')->where('key_code', 'CUBIC-KEY')->update(['sale_profit' => -1.00]);

        fakeTwoOfferOrder();

        $this->artisan('gamivo:backfill-sold-prices --apply')
            ->expectsConfirmation('Gravar as 2 correção(ões) acima?', 'yes')
            ->assertSuccessful();

        expect((float) DB::table('keys')->where('key_code', 'CUBIC-KEY')->value('sold_price'))
            ->toEqualWithDelta(0.42, 0.011);
    });

    it('leaves out a key named by the except-key option', function () {
        insertSoldKey('CUBIC-KEY', '186626', soldPrice: 0.11);
        insertSoldKey('STEAM-KEY', '163729', soldPrice: 0.11);

        fakeTwoOfferOrder();

        $this->artisan('gamivo:backfill-sold-prices --apply --except-key=CUBIC-KEY')
            ->expectsConfirmation('Gravar as 1 correção(ões) acima?', 'yes')
            ->assertSuccessful();

        // a irmã do mesmo pedido continua sendo corrigida
        expect((float) DB::table('keys')->where('key_code', 'CUBIC-KEY')->value('sold_price'))
            ->toEqualWithDelta(0.11, 0.001)
            ->and((float) DB::table('keys')->where('key_code', 'STEAM-KEY')->value('sold_price'))
            ->toEqualWithDelta(0.22, 0.011);
    });

    it('asks the api for the window given by since and until', function () {
        insertSoldKey('CUBIC-KEY', '186626', soldPrice: 0.11);
        insertSoldKey('STEAM-KEY', '163729', soldPrice: 0.11);

        fakeTwoOfferOrder();

        $this->artisan('gamivo:backfill-sold-prices --since=2024-01-01 --until=2024-12-31')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sales/history')
            && str_contains(urldecode($request->url()), '"dateFrom":"2024-01-01"')
            && str_contains(urldecode($request->url()), '"dateTo":"2024-12-31"'));
    });

    it('records the before and after of every correction in the audit trail', function () {
        insertSoldKey('CUBIC-KEY', '186626', soldPrice: 0.11, individualCost: 0.22);
        insertSoldKey('STEAM-KEY', '163729', soldPrice: -1.00);

        fakeTwoOfferOrder();

        $this->artisan('gamivo:backfill-sold-prices')->assertSuccessful();

        $file = collect(Storage::disk('local')->files('diagnostics'))->first();
        $audit = json_decode(Storage::disk('local')->get($file), associative: true);

        $correction = $audit['corrections'][0];

        expect($audit['applied'])->toBeFalse()
            ->and($correction['key_code'])->toBe('CUBIC-KEY')
            ->and($correction['reason'])->toBe('rateio por linha')
            ->and($correction['before']['sold_price'])->toEqualWithDelta(0.11, 0.001)
            ->and($correction['after']['sold_price'])->toEqualWithDelta(0.42, 0.011)
            // o lucro recalculado entra na trilha mesmo sem gravação
            ->and($correction['after']['sale_profit'])->toEqualWithDelta(0.20, 0.011)
            ->and($audit['preserved_refunds'][0]['key_code'])->toBe('STEAM-KEY')
            ->and($audit['preserved_refunds'][0]['reason'])->toBe('prejuízo lançado à mão');
    });

    it('asks the api only for the orders it was given', function () {
        // Com a lista em mãos, o filtro `order` da API dispensa paginar a janela:
        // é o que torna a correção em produção questão de minutos
        insertSoldKey('CUBIC-KEY', '186626', soldPrice: 0.11);
        insertSoldKey('STEAM-KEY', '163729', soldPrice: 0.11);

        fakeTwoOfferOrder();

        $this->artisan('gamivo:backfill-sold-prices --apply --order=order-two')
            ->expectsConfirmation('Gravar as 2 correção(ões) acima?', 'yes')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sales/history')
            && str_contains(urldecode($request->url()), '"order":"order-two"'));

        expect((float) DB::table('keys')->where('key_code', 'CUBIC-KEY')->value('sold_price'))
            ->toEqualWithDelta(0.42, 0.011);
    });

    it('touches nothing when the given order is not in the history', function () {
        insertSoldKey('CUBIC-KEY', '186626', soldPrice: 0.11);
        insertSoldKey('STEAM-KEY', '163729', soldPrice: 0.11);

        fakeTwoOfferOrder();

        $this->artisan('gamivo:backfill-sold-prices --apply --order=pedido-inexistente')
            ->assertSuccessful();

        expect((float) DB::table('keys')->where('key_code', 'CUBIC-KEY')->value('sold_price'))
            ->toEqualWithDelta(0.11, 0.001);
    });
});
