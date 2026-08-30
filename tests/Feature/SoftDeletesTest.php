<?php

/*
|--------------------------------------------------------------------------
| SoftDeletesTest — rede de proteção contra apagamento acidental
|--------------------------------------------------------------------------
|
| Cobre o conjunto curado de tabelas com soft-delete (ver docs/adr/0011):
|
|   1. delete() esconde a linha das queries padrão, mas a mantém no banco
|   2. withTrashed()/restore() recuperam a linha
|   3. índice único parcial: uma linha soft-deletada não bloqueia recriar
|      um registro com o mesmo valor único (suppliers.steam_id,
|      financial_months.(year, month))
|   4. financial_movements soft-deletado sai do cálculo de saldo
|
*/

use App\Domain\Enums\AccountType;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;
use App\Models\Bundle;
use App\Models\FinancialMovement;
use App\Models\Game;
use App\Models\Key;
use App\Models\Supplier;
use App\Models\Trade;
use App\Models\TradeLine;
use App\Services\Financial\FinancialMonthService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinancialMonthFactory;

// Nomes prefixados: o Pest promove funções soltas ao namespace global e
// `seedSupplier` já existe em outro arquivo de teste.
function softDeleteSeedSupplier(array $overrides = []): int
{
    return DB::table('suppliers')->insertGetId(array_merge([
        'name' => 'Fornecedor',
        'steam_id' => '76561198000000009',
        'is_added' => false,
        'has_traded' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function softDeleteSeedGame(array $overrides = []): int
{
    return DB::table('games')->insertGetId(array_merge([
        'name' => 'Some Game',
        'normalized_name' => 'somegame',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function softDeleteSeedBundle(array $overrides = []): int
{
    return DB::table('bundles')->insertGetId(array_merge([
        'name' => 'Humble Bundle',
        'type' => 'bundle',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function softDeleteSeedTrade(int $supplierId): int
{
    return DB::table('trades')->insertGetId([
        'supplier_id' => $supplierId,
        'title' => 'Lista',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** As linhas da trade — `trade_lines` fica fora do conjunto curado (ver docs/adr/0011). */
function softDeleteSeedTradeLine(int $tradeId, int $position): int
{
    return DB::table('trade_lines')->insertGetId([
        'trade_id' => $tradeId,
        'position' => $position,
        'game_name' => 'Some Game',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('soft delete hides the row without erasing it', function () {

    it('disappears from default queries and comes back with withTrashed/restore', function () {
        $id = softDeleteSeedSupplier();

        Supplier::findOrFail($id)->delete();

        $this->assertSoftDeleted('suppliers', ['id' => $id]);
        expect(Supplier::find($id))->toBeNull()
            ->and(Supplier::withTrashed()->find($id))->not->toBeNull();

        Supplier::withTrashed()->findOrFail($id)->restore();

        expect(Supplier::find($id))->not->toBeNull();
    });

    it('applies to every table in the curated set', function () {
        $supplierId = softDeleteSeedSupplier();
        $gameId = softDeleteSeedGame();
        $bundleId = softDeleteSeedBundle();
        $tradeId = softDeleteSeedTrade($supplierId);
        $keyId = Key::factory()->create(['supplier_id' => $supplierId, 'trade_id' => $tradeId])->id;

        $models = [
            [Supplier::class, $supplierId, 'suppliers'],
            [Game::class, $gameId, 'games'],
            [Bundle::class, $bundleId, 'bundles'],
            [Trade::class, $tradeId, 'trades'],
            [Key::class, $keyId, 'keys'],
        ];

        foreach ($models as [$class, $id, $table]) {
            $class::findOrFail($id)->delete();

            $this->assertSoftDeleted($table, ['id' => $id]);
            expect($class::find($id))->toBeNull("{$class} should disappear from default queries");
        }
    });
});

describe('trade_lines survive a soft deleted trade', function () {

    // `trade_lines.trade_id` é cascadeOnDelete, mas o cascade do banco não
    // dispara em soft-delete. As linhas ficam inalcançáveis junto com a trade
    // (só se lê linha por trade) e voltam intactas no restore.
    it('keeps the lines and brings them back when the trade is restored', function () {
        $tradeId = softDeleteSeedTrade(softDeleteSeedSupplier());
        softDeleteSeedTradeLine($tradeId, 0);
        softDeleteSeedTradeLine($tradeId, 1);

        Trade::findOrFail($tradeId)->delete();

        expect(Trade::find($tradeId))->toBeNull()
            ->and(TradeLine::where('trade_id', $tradeId)->count())->toBe(2);

        Trade::withTrashed()->findOrFail($tradeId)->restore();

        expect(Trade::findOrFail($tradeId)->lines()->count())->toBe(2);
    });
});

describe('partial unique index ignores soft deleted rows', function () {

    it('allows recreating a supplier with the steam_id of a soft deleted one', function () {
        $steamId = '76561198000000123';
        $first = softDeleteSeedSupplier(['steam_id' => $steamId]);

        Supplier::findOrFail($first)->delete();

        $second = softDeleteSeedSupplier(['steam_id' => $steamId, 'name' => 'Novo dono do steam_id']);

        expect(Supplier::find($second))->not->toBeNull()
            ->and(Supplier::withTrashed()->count())->toBe(2);
    });

    it('still blocks two live suppliers with the same steam_id', function () {
        $steamId = '76561198000000456';
        softDeleteSeedSupplier(['steam_id' => $steamId]);

        expect(fn () => softDeleteSeedSupplier(['steam_id' => $steamId]))->toThrow(QueryException::class);
    });

    it('allows recreating the same (year, month) of a soft deleted financial_month', function () {
        $first = FinancialMonthFactory::draft(['year' => 2026, 'month' => 3]);
        $first->delete();

        $second = FinancialMonthFactory::draft(['year' => 2026, 'month' => 3]);

        expect($second->exists)->toBeTrue();
    });
});

describe('soft deleted financial_movements drop out of the balance', function () {

    it('is not counted by accountBalances once deleted', function () {
        $month = FinancialMonthFactory::draft();
        FinancialMonthFactory::credit($month, AccountType::Principal, 1000.00);

        $extra = $month->movements()->create([
            'account_type' => AccountType::Principal,
            'direction' => MovementDirection::Credit,
            'category' => MovementCategory::Income,
            'amount' => 250.00,
            'occurred_at' => now()->toDateString(),
            'is_generated' => false,
        ]);

        $service = app(FinancialMonthService::class);
        expect($service->accountBalances($month->fresh('movements'))[AccountType::Principal->value])->toBe(1250.00);

        $extra->delete();

        expect($service->accountBalances($month->fresh('movements'))[AccountType::Principal->value])->toBe(1000.00)
            ->and(FinancialMovement::withTrashed()->whereKey($extra->id)->exists())->toBeTrue();
    });
});
