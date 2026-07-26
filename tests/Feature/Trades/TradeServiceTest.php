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
|     - tf2_min / tf2_max filtram sobre trades.tf2_qty
|     - sort=tf2_qty asc ordena corretamente
|     - is_imported vem no shape retornado
|
*/

use App\Models\Trade;
use App\Services\Trades\TradeService;
use Illuminate\Support\Facades\DB;

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeTrade(array $attrs = []): Trade
{
    return Trade::create(array_merge(['games' => []], $attrs));
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

    it('filters by tf2_min', function () {
        makeTrade(['date' => '2025-06-01', 'tf2_qty' => 3.0]);
        makeTrade(['date' => '2025-06-02', 'tf2_qty' => 12.5]);

        $page = app(TradeService::class)->paginate(['tf2_min' => '10']);

        expect($page->total())->toBe(1);
    });

    it('filters by tf2_max', function () {
        makeTrade(['date' => '2025-06-01', 'tf2_qty' => 3.0]);
        makeTrade(['date' => '2025-06-02', 'tf2_qty' => 12.5]);

        $page = app(TradeService::class)->paginate(['tf2_max' => '10']);

        expect($page->total())->toBe(1);
    });

    it('accepts decimal values', function () {
        makeTrade(['date' => '2025-06-01', 'tf2_qty' => 0.5]);
        makeTrade(['date' => '2025-06-02', 'tf2_qty' => 1.25]);

        $page = app(TradeService::class)->paginate([
            'tf2_min' => '1.0',
            'tf2_max' => '1.5',
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

    it('filters by game name inside the games JSON (case-insensitive)', function () {
        makeTrade([
            'date' => '2025-06-01',
            'games' => [
                ['name' => 'Half-Life 2', 'marketPriceRaw' => '5.00', 'keyCode' => 'AAA'],
                ['name' => 'Portal', 'marketPriceRaw' => '3.00', 'keyCode' => 'BBB'],
            ],
        ]);
        makeTrade([
            'date' => '2025-06-02',
            'games' => [
                ['name' => 'Cyberpunk 2077', 'marketPriceRaw' => '10.00', 'keyCode' => 'CCC'],
            ],
        ]);

        $page = app(TradeService::class)->paginate(['game_search' => 'portal']);

        expect($page->total())->toBe(1);
        expect($page->items()[0]['games'][1]['name'])->toBe('Portal');
    });
});

describe('TradeService::paginate — sort', function () {

    it('sorts by tf2_qty ascending', function () {
        makeTrade(['date' => '2025-06-01', 'tf2_qty' => 10.0]);
        makeTrade(['date' => '2025-06-01', 'tf2_qty' => 2.0]);
        makeTrade(['date' => '2025-06-01', 'tf2_qty' => 5.0]);

        $items = app(TradeService::class)->paginate([], 'tf2_qty', 'asc')->items();
        $qtys = array_map(fn ($t) => (float) $t['tf2_qty'], $items);

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
