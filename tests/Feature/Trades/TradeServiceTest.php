<?php

/*
|--------------------------------------------------------------------------
| TradeService — testes de caracterização
|--------------------------------------------------------------------------
|
|   paginate(filters, sortField, sortDir, perPage)
|     - default (view=open) esconde importadas, ordena por date DESC + id DESC
|     - view=imported retorna só importadas
|     - view=all retorna as duas
|     - date_from / date_to filtram sobre trades.date
|     - amount_min / amount_max filtram sobre trades.amount
|     - sort=amount asc ordena corretamente
|     - is_imported vem no shape retornado
|     - canal de compra e bundle vêm no shape retornado
|
*/

use App\Domain\Enums\TradeCurrency;
use App\Models\Trade;
use App\Models\TradeOffer;
use App\Services\Trades\TradeService;
use Illuminate\Support\Facades\DB;
use Tests\Support\TradeFactory;

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeTrade(array $attrs = []): Trade
{
    return Trade::create($attrs);
}

/**
 * Uma query que vai buscar linhas **por fora** do SELECT de trades.
 *
 * O `withCount` e o `whereHas` também citam `trade_lines`, mas como subquery
 * dentro do SELECT de `trades` — o que interessa aqui é a query separada.
 */
function isSeparateLineQuery(string $sql): bool
{
    return str_contains($sql, 'trade_lines') && ! str_contains($sql, 'trades');
}

// ── paginate ──────────────────────────────────────────────────────────────────

describe('TradeService::paginate — default view', function () {

    it('hides imported trades by default (view=open)', function () {
        makeTrade(['date' => '2025-06-01']);
        makeTrade(['date' => '2025-06-02', 'is_imported' => true]);

        $page = app(TradeService::class)->paginate();

        expect($page->total())->toBe(1);
        expect($page->items()[0]['is_imported'])->toBeFalse();
    });

    it('orders by date DESC then id DESC (tiebreaker)', function () {
        $older = makeTrade(['date' => '2025-01-01']);
        $newerA = makeTrade(['date' => '2025-03-01']);
        $newerB = makeTrade(['date' => '2025-03-01']);

        $items = app(TradeService::class)->paginate()->items();
        $ids = array_map(fn ($t) => $t['id'], $items);

        // Mesma data → id DESC decide (newerB antes de newerA)
        expect($ids)->toBe([$newerB->id, $newerA->id, $older->id]);
    });

    it('exposes is_imported flag in the response shape', function () {
        makeTrade(['date' => '2025-06-01']);

        $item = app(TradeService::class)->paginate()->items()[0];

        expect($item)->toHaveKey('is_imported')
            ->and($item['is_imported'])->toBeFalse();
    });
});

describe('TradeService::paginate — purchase channel', function () {

    it('exposes the purchase channel and the bundle in the response shape', function () {
        $bundleId = DB::table('bundles')->insertGetId(['name' => 'Humble Choice September', 'created_at' => now(), 'updated_at' => now()]);
        makeTrade(['date' => '2025-06-01', 'purchase_channel' => 'bundle_store', 'bundle_id' => $bundleId]);

        $row = app(TradeService::class)->paginate()->items()[0];

        expect($row['purchase_channel'])->toBe('bundle_store')
            ->and($row['bundle_id'])->toBe($bundleId);
    });

    it('exposes the currency the amount was agreed in, TF2 by default', function () {
        makeTrade(['date' => '2025-06-01']);
        makeTrade(['date' => '2025-06-02', 'currency' => 'usd']);

        $currencies = collect(app(TradeService::class)->paginate()->items())->pluck('currency', 'date')->all();

        expect($currencies)->toBe(['02/06/2025' => 'usd', '01/06/2025' => 'tf2']);
    });

    it('exposes a trade without channel as a supplier trade with no bundle', function () {
        makeTrade(['date' => '2025-06-01']);

        $row = app(TradeService::class)->paginate()->items()[0];

        expect($row['purchase_channel'])->toBe('supplier_trade')
            ->and($row['bundle_id'])->toBeNull();
    });
});

describe('TradeService::paginate — view filter', function () {

    it('returns only imported trades when view=imported', function () {
        makeTrade(['date' => '2025-06-01']);
        $imported = makeTrade(['date' => '2025-06-02', 'is_imported' => true]);

        $page = app(TradeService::class)->paginate(['view' => 'imported']);

        expect($page->total())->toBe(1);
        expect($page->items()[0]['id'])->toBe($imported->id);
    });

    it('returns both when view=all', function () {
        makeTrade(['date' => '2025-06-01']);
        makeTrade(['date' => '2025-06-02', 'is_imported' => true]);

        $page = app(TradeService::class)->paginate(['view' => 'all']);

        expect($page->total())->toBe(2);
    });
});

describe('TradeService::paginate — date range', function () {

    it('filters by date_from', function () {
        makeTrade(['date' => '2025-05-01']);
        makeTrade(['date' => '2025-06-15']);

        $page = app(TradeService::class)->paginate(['date_from' => '2025-06-01']);

        expect($page->total())->toBe(1);
    });

    it('filters by date_to', function () {
        makeTrade(['date' => '2025-05-01']);
        makeTrade(['date' => '2025-06-15']);

        $page = app(TradeService::class)->paginate(['date_to' => '2025-06-01']);

        expect($page->total())->toBe(1);
    });

    it('filters by both date_from and date_to', function () {
        makeTrade(['date' => '2025-04-01']);
        makeTrade(['date' => '2025-06-15']);
        makeTrade(['date' => '2025-08-01']);

        $page = app(TradeService::class)->paginate([
            'date_from' => '2025-05-01',
            'date_to' => '2025-07-01',
        ]);

        expect($page->total())->toBe(1);
    });
});

describe('TradeService::paginate — tf2 range', function () {

    it('filters by amount_min', function () {
        makeTrade(['date' => '2025-06-01', 'amount' => 3.0]);
        makeTrade(['date' => '2025-06-02', 'amount' => 12.5]);

        $page = app(TradeService::class)->paginate(['amount_min' => '10']);

        expect($page->total())->toBe(1);
    });

    it('filters by amount_max', function () {
        makeTrade(['date' => '2025-06-01', 'amount' => 3.0]);
        makeTrade(['date' => '2025-06-02', 'amount' => 12.5]);

        $page = app(TradeService::class)->paginate(['amount_max' => '10']);

        expect($page->total())->toBe(1);
    });

    it('accepts decimal values', function () {
        makeTrade(['date' => '2025-06-01', 'amount' => 0.5]);
        makeTrade(['date' => '2025-06-02', 'amount' => 1.25]);

        $page = app(TradeService::class)->paginate([
            'amount_min' => '1.0',
            'amount_max' => '1.5',
        ]);

        expect($page->total())->toBe(1);
    });
});

describe('TradeService::paginate — text search', function () {

    it('filters trades by title using case-insensitive substring', function () {
        makeTrade(['date' => '2025-06-01', 'title' => 'Steam Summer Deal']);
        makeTrade(['date' => '2025-06-02', 'title' => 'Winter clearance']);

        $page = app(TradeService::class)->paginate(['title_search' => 'summer']);

        expect($page->total())->toBe(1);
        expect($page->items()[0]['title'])->toBe('Steam Summer Deal');
    });

    it('filters trades by supplier url using case-insensitive substring', function () {
        $supplierA = DB::table('suppliers')->insertGetId([
            'url' => 'https://steamcommunity.com/id/alice',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $supplierB = DB::table('suppliers')->insertGetId([
            'url' => 'https://steamcommunity.com/id/bob',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        makeTrade(['date' => '2025-06-01', 'supplier_id' => $supplierA]);
        makeTrade(['date' => '2025-06-02', 'supplier_id' => $supplierB]);

        $page = app(TradeService::class)->paginate(['supplier_search' => 'ALICE']);

        expect($page->total())->toBe(1);
        expect($page->items()[0]['supplier']['url'])->toBe('https://steamcommunity.com/id/alice');
    });

    it('ignores empty/whitespace text searches', function () {
        makeTrade(['date' => '2025-06-01', 'title' => 'anything']);

        $page = app(TradeService::class)->paginate([
            'title_search' => '   ',
            'supplier_search' => '',
            'game_search' => '  ',
        ]);

        expect($page->total())->toBe(1);
    });

    it('filters by game name (case-insensitive)', function () {
        TradeFactory::withLines([
            ['game_name' => 'Half-Life 2', 'market_price' => '5.00', 'key_code' => 'AAA'],
            ['game_name' => 'Portal', 'market_price' => '3.00', 'key_code' => 'BBB'],
        ], ['date' => '2025-06-01']);
        TradeFactory::withLines([
            ['game_name' => 'Cyberpunk 2077', 'market_price' => '10.00', 'key_code' => 'CCC'],
        ], ['date' => '2025-06-02']);

        $page = app(TradeService::class)->paginate(['game_search' => 'portal']);

        expect($page->total())->toBe(1);
    });

    it('does not match a key code — only the game name is searched', function () {
        // Regressão do falso positivo do JSON: varrer o documento inteiro fazia
        // o código de uma key casar com a busca por jogo.
        TradeFactory::withLines([
            ['game_name' => 'Portal', 'market_price' => '3.00', 'key_code' => 'CYBERPUNK-XXXX-1'],
        ], ['date' => '2025-06-01']);

        expect(app(TradeService::class)->paginate(['game_search' => 'cyberpunk'])->total())->toBe(0);
    });

    it('does not match a gamivo id', function () {
        TradeFactory::withLines([
            ['game_name' => 'Portal', 'market_price' => '3.00', 'gamivo_id' => '144601'],
        ], ['date' => '2025-06-01']);

        expect(app(TradeService::class)->paginate(['game_search' => '144601'])->total())->toBe(0);
    });

    it('returns a trade once even when several of its lines match', function () {
        TradeFactory::withLines([
            ['game_name' => 'Portal'],
            ['game_name' => 'Portal 2'],
        ], ['date' => '2025-06-01']);

        expect(app(TradeService::class)->paginate(['game_search' => 'portal'])->total())->toBe(1);
    });
});

describe('TradeService::paginate — line loading', function () {

    it('does not carry the lines, only how many there are', function () {
        TradeFactory::withLines(['Portal', 'Half-Life'], ['date' => '2025-06-01']);

        $trade = app(TradeService::class)->paginate()->items()[0];

        // A tela monta ~30 elementos por linha. Mandar as linhas de 40 trades
        // abertas de uma vez é o que travava a aba: elas vêm por `linesFor`.
        expect($trade)->not->toHaveKey('lines')
            ->and($trade['lines_count'])->toBe(2);
    });

    it('never runs a separate query against trade_lines', function () {
        foreach (range(1, 5) as $i) {
            TradeFactory::withLines(['Portal', 'Half-Life'], ['date' => '2025-06-0'.$i]);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (isSeparateLineQuery($query->sql)) {
                $queries[] = $query->sql;
            }
        });

        $page = app(TradeService::class)->paginate(['game_search' => 'portal']);

        // `withCount` conta por subquery dentro do próprio SELECT de trades, e
        // o `whereHas` da busca por jogo também é subquery — nem o caso com
        // filtro de jogo emite query separada.
        expect($page->total())->toBe(5)
            ->and($queries)->toBeEmpty();
    });
});

describe('TradeService::linesFor', function () {

    it('presents the lines in position order', function () {
        $trade = TradeFactory::withLines(['First', 'Second', 'Third'], ['date' => '2025-06-01']);

        $lines = app(TradeService::class)->linesFor($trade);

        expect(array_column($lines, 'game_name'))->toBe(['First', 'Second', 'Third'])
            ->and(array_column($lines, 'position'))->toBe([0, 1, 2]);
    });

    it('never exposes a column the tab was not meant to receive', function () {
        $trade = TradeFactory::withLines(['Portal'], ['date' => '2025-06-01']);

        $line = app(TradeService::class)->linesFor($trade)[0];

        // Projeção explícita: uma coluna nova só chega à tela se alguém a
        // acrescentar aqui de propósito.
        expect(array_keys($line))->toBe([
            'id', 'position', 'game_name', 'market_price', 'popularity',
            'region', 'bundle', 'expires_at', 'key_code', 'gamivo_id', 'is_overstocked', 'is_giveaway',
        ]);
    });

    it('returns only the lines of the trade asked for', function () {
        $mine = TradeFactory::withLines(['Portal'], ['date' => '2025-06-01']);
        TradeFactory::withLines(['Half-Life'], ['date' => '2025-06-02']);

        expect(array_column(app(TradeService::class)->linesFor($mine), 'game_name'))->toBe(['Portal']);
    });
});

describe('TradeService::paginate — sort', function () {

    it('sorts by amount ascending', function () {
        makeTrade(['date' => '2025-06-01', 'amount' => 10.0]);
        makeTrade(['date' => '2025-06-01', 'amount' => 2.0]);
        makeTrade(['date' => '2025-06-01', 'amount' => 5.0]);

        $items = app(TradeService::class)->paginate([], 'amount', 'asc')->items();
        $qtys = array_map(fn ($t) => (float) $t['amount'], $items);

        expect($qtys)->toBe([2.0, 5.0, 10.0]);
    });

    it('falls back to date when sort field is not whitelisted', function () {
        makeTrade(['date' => '2025-06-01']);
        makeTrade(['date' => '2025-06-02']);

        // "title" não é whitelisted — cai no default `date desc`
        $items = app(TradeService::class)->paginate([], 'title', 'desc')->items();
        expect($items[0]['date'])->toBe('02/06/2025');
    });
});

describe('TradeService::paginate — pagination', function () {

    it('respects perPage', function () {
        for ($i = 1; $i <= 25; $i++) {
            makeTrade(['date' => '2025-06-01']);
        }

        $page = app(TradeService::class)->paginate([], 'date', 'desc', 10);

        expect($page->total())->toBe(25);
        expect($page->perPage())->toBe(10);
        expect(count($page->items()))->toBe(10);
    });
});

/** Grava uma rodada da oferta direto, com o valor de cada linha da trade. */
function recordOfferRound(Trade $trade, int $round, array $prices, ?float $requested = null): TradeOffer
{
    $offer = TradeOffer::create([
        'trade_id' => $trade->id, 'round' => $round, 'profit_percent' => 80 - 10 * $round,
        'currency' => TradeCurrency::Eur, 'tf2_rate' => 0.95, 'requested_total' => $requested,
    ]);

    foreach ($trade->lines as $i => $line) {
        if (array_key_exists($i, $prices)) {
            $offer->lines()->create(['trade_line_id' => $line->id, 'game_name' => $line->game_name, 'region' => $line->region, 'price' => $prices[$i]]);
        }
    }

    return $offer;
}

describe('TradeService — supplier offer', function () {

    it('tells the listing the round of the current offer, without loading its values', function () {
        $offered = TradeFactory::withLines(['Portal', 'Quake'], ['date' => '2025-06-01']);
        recordOfferRound($offered, 1, [0 => 2.00, 1 => 3.00]);
        recordOfferRound($offered, 2, [0 => 2.20, 1 => 3.30], requested: 6.00);
        TradeFactory::withLines(['Half-Life'], ['date' => '2025-06-02']);

        $items = collect(app(TradeService::class)->paginate()->items())->keyBy('id');

        expect($items[$offered->id]['offer_round'])->toBe(2)
            ->and($items[$offered->id]['offer_is_final'])->toBeFalse()
            ->and($items->first(fn ($item) => $item['id'] !== $offered->id)['offer_round'])->toBeNull();
    });

    it('marks the third round as final in the listing', function () {
        $trade = TradeFactory::withLines(['Portal'], ['date' => '2025-06-01']);
        foreach ([1, 2, 3] as $round) {
            recordOfferRound($trade, $round, [0 => 1.00 + $round]);
        }

        expect(app(TradeService::class)->paginate()->items()[0]['offer_is_final'])->toBeTrue();
    });

    it('presents every round, oldest first, with the value of each line', function () {
        $trade = TradeFactory::withLines(['Portal', 'Quake', 'Doom'], ['date' => '2025-06-01']);
        recordOfferRound($trade, 1, [0 => 2.00, 1 => 3.00]);
        recordOfferRound($trade, 2, [0 => 2.20, 1 => 3.30], requested: 6.00);

        $offers = app(TradeService::class)->offersFor($trade);
        $lineIds = $trade->lines->pluck('id')->all();

        expect(array_column($offers, 'round'))->toBe([1, 2])
            ->and($offers[1])->toMatchArray([
                'is_final' => false,
                'profit_percent' => 60.0,
                'currency' => 'eur',
                'requested_total' => 6.0,
                'total_price' => 5.5,
            ])
            // Doom ficou fora da oferta: não tem valor
            ->and($offers[1]['lines'])->toBe([
                ['trade_line_id' => $lineIds[0], 'game_name' => 'Portal', 'price' => 2.2],
                ['trade_line_id' => $lineIds[1], 'game_name' => 'Quake', 'price' => 3.3],
            ]);
    });

    it('keeps the value of a line deleted after the offer, with its name', function () {
        $trade = TradeFactory::withLines(['Portal', 'Quake'], ['date' => '2025-06-01']);
        recordOfferRound($trade, 1, [0 => 2.00, 1 => 3.00]);
        DB::table('trade_lines')->where('id', $trade->lines[1]->id)->delete();

        $offer = app(TradeService::class)->offersFor($trade)[0];

        // Soma no total, e a tela sabe o nome para dizer de onde veio
        expect($offer['total_price'])->toBe(5.0)
            ->and($offer['lines'][1])->toBe(['trade_line_id' => null, 'game_name' => 'Quake', 'price' => 3.0]);
    });

    it('presents no rounds for a trade without an offer', function () {
        expect(app(TradeService::class)->offersFor(TradeFactory::withLines(['Portal'])))->toBe([]);
    });
});
