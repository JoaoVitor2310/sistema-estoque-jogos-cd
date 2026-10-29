<?php

/*
|--------------------------------------------------------------------------
| Migration 2026_10_29_000002_rename_delivery_uuid_to_uuid_in_trades_table
|--------------------------------------------------------------------------
|
| O rename não pode quebrar link de entrega já enviado ao supplier: o valor da
| coluna atravessa a migration e a rota pública continua resolvendo a trade
| por ele. A migration já rodou no setup da suíte; o teste volta um passo
| (down) para semear no esquema antigo e reaplica o up().
|
*/

use App\Domain\Trades\DeliveryCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TradeFactory;

function renameDeliveryUuidMigration(): object
{
    return require base_path('database/migrations/2026_10_29_000002_rename_delivery_uuid_to_uuid_in_trades_table.php');
}

it('keeps the uuid of a delivery link sent before the rename', function () {
    $trade = TradeFactory::withLines(['Portal']);
    $credential = DeliveryCredential::issue();
    $trade->forceFill($credential)->save();

    $migration = renameDeliveryUuidMigration();
    $migration->down();

    // No esquema antigo, o link enviado estava nesta coluna
    expect(Schema::hasColumn('trades', 'delivery_uuid'))->toBeTrue()
        ->and(DB::table('trades')->where('id', $trade->id)->value('delivery_uuid'))->toBe($credential['uuid']);

    $migration->up();

    expect(Schema::hasColumn('trades', 'delivery_uuid'))->toBeFalse()
        ->and(DB::table('trades')->where('id', $trade->id)->value('uuid'))->toBe($credential['uuid']);

    // O mesmo link abre a página da entrega (trancada até o token)
    $this->get("/deliveries/{$credential['uuid']}")->assertStatus(200);
});

it('keeps the uuid unique after the rename', function () {
    $first = TradeFactory::withLines(['Portal']);
    $first->forceFill(DeliveryCredential::issue())->save();
    $second = TradeFactory::withLines(['Portal 2']);

    expect(fn () => DB::table('trades')->where('id', $second->id)->update(['uuid' => $first->fresh()->uuid]))
        ->toThrow(Illuminate\Database\QueryException::class);
});
