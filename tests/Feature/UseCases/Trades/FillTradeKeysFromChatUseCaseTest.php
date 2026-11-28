<?php

/*
|--------------------------------------------------------------------------
| FillTradeKeysFromChatUseCase — orquestração
|--------------------------------------------------------------------------
|
| As keys coladas no chat entram nas linhas da trade pelo mesmo caminho da
| página da entrega, só nas linhas da oferta e só enquanto a trade negocia.
| Toda recusa sai antes de gravar.
|
*/

use App\Domain\Enums\ChatDeliveryRefusal;
use App\Domain\Trades\ChatDeliveryRefused;
use App\Models\Trade;
use App\UseCases\Trades\DTO\ChatKeysDTO;
use App\UseCases\Trades\FillTradeKeysFromChatUseCase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ChatDeliveryFactory;

function fillChatKeys(Trade $trade, array $keys, string $steamId = ChatDeliveryFactory::STEAM_ID): ?Trade
{
    return app(FillTradeKeysFromChatUseCase::class)->execute($steamId, $trade->supplier_trade_number, new ChatKeysDTO($keys));
}

function chatFillRefusal(callable $call): ChatDeliveryRefused
{
    try {
        $call();
    } catch (ChatDeliveryRefused $e) {
        return $e;
    }

    throw new RuntimeException('Expected the chat delivery to be refused.');
}

beforeEach(fn () => ChatDeliveryFactory::seedDependencies());

describe('FillTradeKeysFromChatUseCase', function () {

    it('writes each key on its line of the trade', function () {
        $trade = ChatDeliveryFactory::trade();

        fillChatKeys($trade, ChatDeliveryFactory::allKeys($trade));

        $lines = ChatDeliveryFactory::lines($trade);
        expect($lines['Half-Life']->key_code)->toBe('AAAAA-BBBBB-CCCCC')
            ->and($lines['Portal']->key_code)->toBe('DDDDD-EEEEE-FFFFF');
    });

    it('replaces only the keys of the lines pasted again', function () {
        $trade = ChatDeliveryFactory::trade();
        fillChatKeys($trade, ChatDeliveryFactory::allKeys($trade));
        $halfLife = ChatDeliveryFactory::lines($trade)['Half-Life'];

        fillChatKeys($trade, [$halfLife->id => 'GGGGG-HHHHH-IIIII']);

        $lines = ChatDeliveryFactory::lines($trade);
        expect($lines['Half-Life']->key_code)->toBe('GGGGG-HHHHH-IIIII')
            ->and($lines['Portal']->key_code)->toBe('DDDDD-EEEEE-FFFFF');
    });

    it('leaves the region and the other columns of the line as they were', function () {
        // Pelo chat vem só a key: região, bundle e validade ficam com a equipe.
        $trade = ChatDeliveryFactory::trade();
        $portal = ChatDeliveryFactory::lines($trade)['Portal'];
        DB::table('trade_lines')->where('id', $portal->id)->update(['region' => 'EU', 'bundle' => 'Humble']);

        fillChatKeys($trade, [$portal->id => 'DDDDD-EEEEE-FFFFF']);

        $portal->refresh();
        expect($portal->key_code)->toBe('DDDDD-EEEEE-FFFFF')
            ->and($portal->region)->toBe('EU')
            ->and($portal->bundle)->toBe('Humble');
    });

    it('does not close the delivery', function () {
        $trade = ChatDeliveryFactory::trade();

        fillChatKeys($trade, ChatDeliveryFactory::allKeys($trade));

        expect($trade->fresh()->delivered_at)->toBeNull();
    });

    it('answers null for a number the supplier does not have', function () {
        $trade = ChatDeliveryFactory::trade();

        expect(app(FillTradeKeysFromChatUseCase::class)->execute(ChatDeliveryFactory::STEAM_ID, 99, new ChatKeysDTO([1 => 'AAAAA'])))->toBeNull()
            // O número é por supplier: o mesmo número de outro supplier não é esta trade.
            ->and(fillChatKeys($trade, ChatDeliveryFactory::allKeys($trade), ChatDeliveryFactory::OTHER_STEAM_ID))->toBeNull()
            ->and(ChatDeliveryFactory::lines($trade)['Portal']->key_code)->toBeNull();
    });

    it('refuses a trade that was already delivered, and writes nothing', function () {
        $trade = ChatDeliveryFactory::trade();
        DB::table('trades')->where('id', $trade->id)->update(['delivered_at' => now()]);

        $refusal = chatFillRefusal(fn () => fillChatKeys($trade, ChatDeliveryFactory::allKeys($trade)));

        expect($refusal->reason)->toBe(ChatDeliveryRefusal::TradeNotNegotiating)
            ->and(ChatDeliveryFactory::lines($trade)['Portal']->key_code)->toBeNull();
    });

    it('refuses a line that is not in the offer, and writes none of the others', function () {
        $trade = ChatDeliveryFactory::trade();
        $extra = DB::table('trade_lines')->insertGetId([
            'trade_id' => $trade->id, 'game_name' => 'Left out of the offer', 'position' => 9,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $refusal = chatFillRefusal(fn () => fillChatKeys($trade, ChatDeliveryFactory::allKeys($trade) + [$extra => 'XXXXX-YYYYY-ZZZZZ']));

        expect($refusal->reason)->toBe(ChatDeliveryRefusal::LineNotInOffer)
            ->and($refusal->lineIds)->toBe([$extra])
            ->and(ChatDeliveryFactory::lines($trade)['Portal']->key_code)->toBeNull();
    });

    it('refuses a line of another trade', function () {
        $trade = ChatDeliveryFactory::trade();
        $other = ChatDeliveryFactory::trade(ChatDeliveryFactory::OTHER_STEAM_ID);
        $foreign = ChatDeliveryFactory::lines($other)['Portal']->id;

        $refusal = chatFillRefusal(fn () => fillChatKeys($trade, [$foreign => 'XXXXX-YYYYY-ZZZZZ']));

        expect($refusal->reason)->toBe(ChatDeliveryRefusal::LineNotInOffer)
            ->and(ChatDeliveryFactory::lines($other)['Portal']->key_code)->toBeNull();
    });

    it('refuses a trade without an offer', function () {
        $trade = ChatDeliveryFactory::trade();
        DB::table('trade_offers')->where('trade_id', $trade->id)->delete();

        expect(chatFillRefusal(fn () => fillChatKeys($trade, ChatDeliveryFactory::allKeys($trade)))->reason)
            ->toBe(ChatDeliveryRefusal::NoOffer);
    });
});
