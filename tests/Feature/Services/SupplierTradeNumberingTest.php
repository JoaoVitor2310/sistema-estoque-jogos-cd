<?php

/*
|--------------------------------------------------------------------------
| SupplierTradeNumbering — o número da trade por supplier
|--------------------------------------------------------------------------
|
| O número sai do contador do supplier e nunca volta. A atribuição em cada
| caminho que cria trade está nos testes de cada UseCase.
|
*/

use App\Models\Supplier;
use App\Services\Trades\SupplierTradeNumbering;
use App\UseCases\Trades\CreateTradeUseCase;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;

function numberingSupplier(string $steamId = '76561198000000501'): Supplier
{
    return Supplier::create(['steam_id' => $steamId, 'url' => "https://steamcommunity.com/profiles/{$steamId}"]);
}

function reserveNumber(?int $supplierId): ?int
{
    return DB::transaction(fn () => app(SupplierTradeNumbering::class)->for($supplierId)['supplier_trade_number']);
}

it('numbers the trades of a supplier from 1, one after the other', function () {
    $supplier = numberingSupplier();

    expect(reserveNumber($supplier->id))->toBe(1)
        ->and(reserveNumber($supplier->id))->toBe(2)
        ->and(reserveNumber($supplier->id))->toBe(3);
});

it('counts each supplier on its own', function () {
    $first = numberingSupplier('76561198000000501');
    $second = numberingSupplier('76561198000000502');

    reserveNumber($first->id);
    reserveNumber($first->id);

    expect(reserveNumber($second->id))->toBe(1)
        ->and(reserveNumber($first->id))->toBe(3);
});

it('gives no number to a trade without supplier', function () {
    expect(reserveNumber(null))->toBeNull();
});

it('never gives a number again, even when the trade that had it is gone', function () {
    // Sai do contador, não do maior número das trades: apagar a trade de número
    // mais alto — de verdade, não soft delete — não devolve o número dela.
    $supplier = numberingSupplier();
    $url = $supplier->url;

    app(CreateTradeUseCase::class)->execute(['supplierUrl' => $url]);
    $second = app(CreateTradeUseCase::class)->execute(['supplierUrl' => $url]);
    $second->forceDelete();

    $third = app(CreateTradeUseCase::class)->execute(['supplierUrl' => $url]);

    expect($second->supplier_trade_number)->toBe(2)
        ->and($third->supplier_trade_number)->toBe(3);
});

it('does not touch the updated_at of the supplier', function () {
    // Subir o contador não é uma edição do supplier.
    $supplier = numberingSupplier();
    $before = $supplier->fresh()->updated_at;

    $this->travel(1)->hours();
    reserveNumber($supplier->id);

    expect($supplier->fresh()->updated_at->equalTo($before))->toBeTrue();
});

it('refuses to reserve a number outside the transaction that records the trade', function () {
    // Fora da transação a trava do supplier acabaria antes da gravação, e duas
    // criações ao mesmo tempo poderiam pegar o mesmo número. A suíte roda cada
    // teste dentro de uma transação (RefreshDatabase): sai dela para provar a
    // guarda, e volta ao mesmo nível no fim.
    $levels = DB::transactionLevel();

    for ($i = 0; $i < $levels; $i++) {
        DB::rollBack();
    }

    try {
        expect(fn () => app(SupplierTradeNumbering::class)->for(1))
            ->toThrow(LogicException::class);
    } finally {
        for ($i = 0; $i < $levels; $i++) {
            DB::beginTransaction();
        }
    }
});

it('locks the supplier in a mode that does not deadlock with the foreign key of the new trade', function () {
    // Quem reserva o número já inseriu a trade, e a chave estrangeira segura o
    // supplier em FOR KEY SHARE. FOR UPDATE (o lockForUpdate do Laravel) é
    // incompatível com ele: duas criações simultâneas davam deadlock. O SQLite
    // da suíte ignora o modo da trava, então a guarda compila para Postgres.
    $grammar = new PostgresGrammar;
    $grammar->setConnection(DB::connection());

    $sql = $grammar->compileSelect(app(SupplierTradeNumbering::class)->counterQuery(1));

    expect($sql)->toEndWith('for no key update')
        ->and($sql)->not->toContain('for update');
});
