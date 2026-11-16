<?php

/*
|--------------------------------------------------------------------------
| Migration 2026_11_15_000001_number_trades_per_supplier — backfill
|--------------------------------------------------------------------------
|
| As trades que já existiam ganham número por supplier, na ordem de criação,
| apagadas incluídas, e o contador de cada supplier fica no último número dado.
| A migration já rodou no setup da suíte; o teste volta um passo (down) para
| semear no esquema antigo e reaplica o up().
|
*/

use Illuminate\Support\Facades\DB;

function numberTradesMigration(): object
{
    return require base_path('database/migrations/2026_11_15_000001_number_trades_per_supplier.php');
}

function oldSchemaTrade(?int $supplierId, ?string $createdAt, ?string $deletedAt = null): int
{
    return DB::table('trades')->insertGetId([
        'supplier_id' => $supplierId,
        'date' => '2026-01-01',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
        'deleted_at' => $deletedAt,
    ]);
}

function oldSchemaSupplier(string $steamId): int
{
    return DB::table('suppliers')->insertGetId([
        'steam_id' => $steamId,
        'url' => "https://steamcommunity.com/profiles/{$steamId}",
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('numbers the existing trades of each supplier by creation, deleted ones included', function () {
    $migration = numberTradesMigration();
    $migration->down();

    $first = oldSchemaSupplier('76561198000000801');
    $second = oldSchemaSupplier('76561198000000802');

    $late = oldSchemaTrade($first, '2026-03-01 10:00:00');
    $early = oldSchemaTrade($first, '2026-01-01 10:00:00');
    $deleted = oldSchemaTrade($first, '2026-02-01 10:00:00', deletedAt: '2026-02-02 10:00:00');
    $undated = oldSchemaTrade($first, null);
    $other = oldSchemaTrade($second, '2026-01-05 10:00:00');
    $noSupplier = oldSchemaTrade(null, '2026-01-05 10:00:00');

    $migration->up();

    $number = fn (int $id) => DB::table('trades')->where('id', $id)->value('supplier_trade_number');

    expect($number($early))->toBe(1)
        ->and($number($deleted))->toBe(2)
        ->and($number($late))->toBe(3)
        // Sem data de criação, por último — igual no Postgres e no SQLite.
        ->and($number($undated))->toBe(4)
        ->and($number($other))->toBe(1)
        ->and($number($noSupplier))->toBeNull();
});

it('leaves the counter of each supplier on the last number given', function () {
    $migration = numberTradesMigration();
    $migration->down();

    $busy = oldSchemaSupplier('76561198000000801');
    $idle = oldSchemaSupplier('76561198000000802');
    oldSchemaTrade($busy, '2026-01-01 10:00:00');
    oldSchemaTrade($busy, '2026-01-02 10:00:00', deletedAt: '2026-01-03 10:00:00');

    $migration->up();

    // A apagada conta: a próxima trade do supplier é a 3, não a 2.
    expect(DB::table('suppliers')->where('id', $busy)->value('last_trade_number'))->toBe(2)
        ->and(DB::table('suppliers')->where('id', $idle)->value('last_trade_number'))->toBe(0);
});
