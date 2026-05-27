<?php

/*
|--------------------------------------------------------------------------
| TradeService — testes de caracterização
|--------------------------------------------------------------------------
|
| Cobre os dois métodos públicos do serviço:
|
|   allWithStockedStatus()
|     - Retorna trades em ordem decrescente de criação (DESC)
|     - is_stocked = false quando nenhum keyCode da trade existe em `keys`
|     - is_stocked = true  quando ao menos um keyCode existe em `keys`
|     - Trades sem rows retornam is_stocked = false
|
|   isStocked(array $rows)
|     - Retorna false para array vazio
|     - Retorna false quando nenhum keyCode bate com `keys`
|     - Retorna true  quando ao menos um keyCode existe em `keys`
|     - Ignora rows cujo keyCode é null ou string vazia
|
*/

use App\Models\Trade;
use App\Services\Trades\TradeService;
use Illuminate\Support\Facades\DB;

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Insere uma key mínima na tabela `keys` com o key_code informado. */
function seedKey(string $keyCode): void
{
    DB::table('suppliers')->insertOrIgnore([
        'id' => 1,
        'supplier_url' => 'https://steamcommunity.com/id/seed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('keys')->insert([
        'key_code' => $keyCode,
        'supplier_id' => 1,
        'supplier_url' => 'https://steamcommunity.com/id/seed',
        'game_name' => 'Seed Game',
        'market_price' => 5.00,
        'acquired_at' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** Cria uma Trade com um ou mais rows no banco. */
function makeTrade(array $rows = [], ?string $createdAt = null): Trade
{
    $trade = Trade::create(['rows' => $rows]);

    if ($createdAt) {
        $trade->forceFill(['created_at' => $createdAt])->save();
    }

    return $trade;
}

/** Row mínima no formato que o frontend persiste. */
function tradeRow(string $keyCode, string $name = 'Test Game'): array
{
    return ['keyCode' => $keyCode, 'name' => $name, 'marketPriceRaw' => '5.00'];
}

// ── allWithStockedStatus ──────────────────────────────────────────────────────

describe('TradeService::allWithStockedStatus', function () {

    it('returns trades in descending order of creation', function () {
        makeTrade([], '2025-01-01 10:00:00');
        makeTrade([], '2025-03-01 10:00:00');
        makeTrade([], '2025-02-01 10:00:00');

        $result = app(TradeService::class)->allWithStockedStatus();

        $dates = $result->pluck('created_at')->map(fn ($d) => substr($d, 0, 10))->values();

        expect($dates[0])->toBe('2025-03-01')
            ->and($dates[1])->toBe('2025-02-01')
            ->and($dates[2])->toBe('2025-01-01');
    });

    it('returns is_stocked = false when no key_code exists in keys table', function () {
        makeTrade([tradeRow('AAAAA-11111-BBBBB')]);

        $result = app(TradeService::class)->allWithStockedStatus();

        expect($result->first()['is_stocked'])->toBeFalse();
    });

    it('returns is_stocked = true when at least one key_code is in the keys table', function () {
        seedKey('AAAAA-11111-BBBBB');
        makeTrade([
            tradeRow('AAAAA-11111-BBBBB'),
            tradeRow('CCCCC-33333-DDDDD'),
        ]);

        $result = app(TradeService::class)->allWithStockedStatus();

        expect($result->first()['is_stocked'])->toBeTrue();
    });

    it('returns is_stocked = false for a trade with no rows', function () {
        makeTrade([]);

        $result = app(TradeService::class)->allWithStockedStatus();

        expect($result->first()['is_stocked'])->toBeFalse();
    });

    it('computes is_stocked independently per trade', function () {
        seedKey('STOCKED-KEY-001');

        makeTrade([tradeRow('STOCKED-KEY-001')]);
        makeTrade([tradeRow('NOT-IN-DB-KEY-X')]);

        $result = app(TradeService::class)->allWithStockedStatus();

        // Ordem DESC — a segunda inserida vem primeiro
        $stockedFlags = $result->pluck('is_stocked')->values();

        expect($stockedFlags)->toContain(true)
            ->and($stockedFlags)->toContain(false);
    });
});

// ── isStocked ─────────────────────────────────────────────────────────────────

describe('TradeService::isStocked', function () {

    it('returns false for an empty rows array', function () {
        expect(app(TradeService::class)->isStocked([]))->toBeFalse();
    });

    it('returns false when no keyCode matches the keys table', function () {
        $rows = [tradeRow('XXXXX-99999-YYYYY')];

        expect(app(TradeService::class)->isStocked($rows))->toBeFalse();
    });

    it('returns true when at least one keyCode exists in the keys table', function () {
        seedKey('MATCH-KEY-00001');

        $rows = [
            tradeRow('NO-MATCH-00000'),
            tradeRow('MATCH-KEY-00001'),
        ];

        expect(app(TradeService::class)->isStocked($rows))->toBeTrue();
    });

    it('ignores rows with null or empty keyCode', function () {
        $rows = [
            ['keyCode' => null, 'name' => 'Game A'],
            ['keyCode' => '',   'name' => 'Game B'],
            ['name' => 'Game C'],                     // sem campo keyCode
        ];

        expect(app(TradeService::class)->isStocked($rows))->toBeFalse();
    });
});
