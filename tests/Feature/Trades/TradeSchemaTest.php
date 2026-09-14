<?php

/*
|--------------------------------------------------------------------------
| TradeSchemaTest — o que sobrou de `trades` depois da normalização
|--------------------------------------------------------------------------
|
| A suíte roda as migrations do zero, então este teste é o que garante que a
| passada inteira — criar `trade_lines`, converter o JSON, afrouxar a coluna e
| derrubá-la — chega no schema esperado.
|
| Vale como regressão do replay: se alguém reordenar as migrations e o backfill
| passar a rodar depois do drop, a conversão lê uma coluna que não existe e a
| suíte inteira quebra aqui primeiro.
|
*/

use App\Domain\Enums\PurchaseChannel;
use App\Models\Trade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TradeFactory;

describe('trades schema', function () {

    it('no longer has the games column', function () {
        expect(Schema::hasColumn('trades', 'games'))->toBeFalse();
    });

    it('keeps trade_lines as the place a line lives', function () {
        expect(Schema::hasTable('trade_lines'))->toBeTrue()
            ->and(Schema::hasColumn('trade_lines', 'game_name'))->toBeTrue();
    });

    it('gives a new trade the supplier trade channel, in memory and in the database', function () {
        // O default do model espelha o do banco: sem ele, o `purchase_channel`
        // de uma trade recém-criada só existiria depois de um fresh().
        $trade = Trade::create(['title' => 'Sem canal']);

        expect($trade->purchase_channel)->toBe(PurchaseChannel::SupplierTrade)
            ->and($trade->fresh()->purchase_channel)->toBe(PurchaseChannel::SupplierTrade);

        $rawId = DB::table('trades')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
        expect(DB::table('trades')->where('id', $rawId)->value('purchase_channel'))->toBe('supplier_trade');
    });

    it('creates a trade without ever mentioning games', function () {
        $trade = Trade::create(['title' => 'Sem games']);

        expect($trade->fresh()->title)->toBe('Sem games');
    });

    it('still reaches the lines of a trade through the relation', function () {
        $trade = TradeFactory::withLines(['Half-Life', 'Portal']);

        expect($trade->lines->pluck('game_name')->all())->toBe(['Half-Life', 'Portal']);
    });

    // `trades` é soft-delete (docs/adr/0011) e `trade_lines` não. O cascade do
    // banco continua existindo, mas só um apagamento físico o dispara — o que
    // preserva a linha quando a trade é apenas ocultada, e a devolve no restore.
    it('keeps the lines when the trade is only soft deleted', function () {
        $trade = TradeFactory::withLines(['Half-Life']);

        $trade->delete();

        expect(DB::table('trade_lines')->where('trade_id', $trade->id)->count())->toBe(1);
    });

    it('drops the lines with the trade on a force delete (cascade)', function () {
        $trade = TradeFactory::withLines(['Half-Life']);

        $trade->forceDelete();

        expect(DB::table('trade_lines')->where('trade_id', $trade->id)->count())->toBe(0);
    });
});
