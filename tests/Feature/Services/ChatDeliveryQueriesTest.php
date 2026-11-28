<?php

/*
|--------------------------------------------------------------------------
| ChatDeliveryQueries — as consultas da entrega pelo chat
|--------------------------------------------------------------------------
|
| O preenchimento, a entrega e a resposta do preenchimento leem daqui: qual
| trade é travada e quais linhas contam. A trava é guardada compilando a
| consulta para Postgres, porque o SQLite da suíte ignora o modo.
|
*/

use App\Services\Trades\ChatDeliveryQueries;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;
use Tests\Support\ChatDeliveryFactory;

beforeEach(fn () => ChatDeliveryFactory::seedDependencies());

describe('ChatDeliveryQueries', function () {

    it('locks the trade for the write', function () {
        // Sem a trava, uma key colada atrasada entraria numa trade que a
        // confirmação acabou de fechar. FOR UPDATE, e não FOR NO KEY UPDATE:
        // nenhum dos dois caminhos insere linha com chave estrangeira para a
        // trade antes de travar (ver docs/agents/testing.md).
        $grammar = new PostgresGrammar;
        $grammar->setConnection(DB::connection());

        $sql = $grammar->compileSelect(app(ChatDeliveryQueries::class)->lockedTrade(ChatDeliveryFactory::STEAM_ID, 1)->toBase());

        expect($sql)->toEndWith('for update');
    });

    it('finds the trade of the supplier by its number, and not the one of another supplier', function () {
        $trade = ChatDeliveryFactory::trade();
        ChatDeliveryFactory::trade(ChatDeliveryFactory::OTHER_STEAM_ID);
        $queries = app(ChatDeliveryQueries::class);

        expect($queries->lockedTrade(ChatDeliveryFactory::STEAM_ID, $trade->supplier_trade_number)->first()?->id)->toBe($trade->id)
            ->and($queries->lockedTrade(ChatDeliveryFactory::STEAM_ID, 99)->first())->toBeNull();
    });

    it('delivers the offer lines that still have a line in the trade, in the order of the offer', function () {
        $trade = ChatDeliveryFactory::trade();
        $lines = ChatDeliveryFactory::lines($trade);
        DB::table('trade_lines')->where('id', $lines['Half-Life']->id)->delete();

        expect(app(ChatDeliveryQueries::class)->deliverableLineIds($trade))->toBe([$lines['Portal']->id]);
    });

    it('delivers nothing from a trade without an offer', function () {
        $trade = ChatDeliveryFactory::trade();
        DB::table('trade_offers')->where('trade_id', $trade->id)->delete();

        expect(app(ChatDeliveryQueries::class)->deliverableOfferLines($trade))->toBeEmpty();
    });

    it('lists the lines still without a key', function () {
        $trade = ChatDeliveryFactory::trade();
        $lines = ChatDeliveryFactory::lines($trade);
        DB::table('trade_lines')->where('id', $lines['Half-Life']->id)->update(['key_code' => 'AAAAA-BBBBB-CCCCC']);

        expect(app(ChatDeliveryQueries::class)->linesWithoutKey($trade))->toBe([$lines['Portal']->id]);
    });
});
