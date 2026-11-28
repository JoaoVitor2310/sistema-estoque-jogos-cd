<?php

/*
|--------------------------------------------------------------------------
| DeliverTradeFromChatUseCase — orquestração
|--------------------------------------------------------------------------
|
| A confirmação no chat fecha a entrega como o botão da página, com duas
| diferenças: só completa, e com o total da oferta como valor acertado. A
| confirmação repetida tem motivo próprio, que o chatbot trata como sucesso.
|
*/

use App\Domain\Enums\ChatDeliveryRefusal;
use App\Domain\Trades\ChatDeliveryRefused;
use App\Mail\TradeDeliveredMail;
use App\Models\Trade;
use App\UseCases\Trades\DeliverTradeFromChatUseCase;
use App\UseCases\Trades\DTO\ChatKeysDTO;
use App\UseCases\Trades\FillTradeKeysFromChatUseCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\ChatDeliveryFactory;

function deliverFromChat(Trade $trade, string $steamId = ChatDeliveryFactory::STEAM_ID): ?Trade
{
    return app(DeliverTradeFromChatUseCase::class)->execute($steamId, $trade->supplier_trade_number);
}

function filledChatTrade(): Trade
{
    $trade = ChatDeliveryFactory::trade();

    app(FillTradeKeysFromChatUseCase::class)->execute(
        ChatDeliveryFactory::STEAM_ID,
        $trade->supplier_trade_number,
        new ChatKeysDTO(ChatDeliveryFactory::allKeys($trade)),
    );

    return $trade;
}

function chatDeliverRefusal(callable $call): ChatDeliveryRefused
{
    try {
        $call();
    } catch (ChatDeliveryRefused $e) {
        return $e;
    }

    throw new RuntimeException('Expected the chat delivery to be refused.');
}

beforeEach(function () {
    ChatDeliveryFactory::seedDependencies();
    Mail::fake();
});

describe('DeliverTradeFromChatUseCase', function () {

    it('closes the delivery with the offer total as the agreed amount', function () {
        $trade = filledChatTrade();

        deliverFromChat($trade);

        $trade->refresh();
        expect($trade->delivered_at)->not->toBeNull()
            // Half-Life 2,46 + Portal 3,34
            ->and($trade->amount)->toBe('5.80');
    });

    it('replaces an amount typed before with the offer total', function () {
        // No chat, mandar as keys é aceitar a oferta vigente.
        $trade = filledChatTrade();
        DB::table('trades')->where('id', $trade->id)->update(['amount' => '9.99']);

        deliverFromChat($trade);

        expect($trade->fresh()->amount)->toBe('5.80');
    });

    it('tells the team, as the delivery page does', function () {
        $trade = filledChatTrade();

        deliverFromChat($trade);

        Mail::assertSent(TradeDeliveredMail::class, 1);
    });

    it('refuses while a line of the offer has no key, and says which', function () {
        $trade = ChatDeliveryFactory::trade();
        $lines = ChatDeliveryFactory::lines($trade);
        app(FillTradeKeysFromChatUseCase::class)->execute(
            ChatDeliveryFactory::STEAM_ID,
            $trade->supplier_trade_number,
            new ChatKeysDTO([$lines['Half-Life']->id => 'AAAAA-BBBBB-CCCCC']),
        );

        $refusal = chatDeliverRefusal(fn () => deliverFromChat($trade));

        expect($refusal->reason)->toBe(ChatDeliveryRefusal::MissingKeys)
            ->and($refusal->lineIds)->toBe([$lines['Portal']->id])
            ->and($trade->fresh()->delivered_at)->toBeNull()
            ->and($trade->fresh()->amount)->toBeNull();

        Mail::assertNothingSent();
    });

    it('leaves out of the delivery and of the total a game the team removed after the offer', function () {
        $trade = filledChatTrade();
        DB::table('trade_lines')->where('id', ChatDeliveryFactory::lines($trade)['Portal']->id)->delete();

        deliverFromChat($trade);

        expect($trade->fresh()->amount)->toBe('2.46');
    });

    it('answers a repeated confirmation with its own reason, without moving the mark', function () {
        $trade = filledChatTrade();
        deliverFromChat($trade);
        $first = $trade->fresh()->delivered_at;

        $this->travel(1)->hours();
        $refusal = chatDeliverRefusal(fn () => deliverFromChat($trade));

        expect($refusal->reason)->toBe(ChatDeliveryRefusal::AlreadyDelivered)
            ->and($trade->fresh()->delivered_at->toIso8601String())->toBe($first->toIso8601String());

        Mail::assertSent(TradeDeliveredMail::class, 1);
    });

    it('refuses an imported trade as no longer negotiating', function () {
        $trade = filledChatTrade();
        DB::table('trades')->where('id', $trade->id)->update(['is_imported' => true]);

        expect(chatDeliverRefusal(fn () => deliverFromChat($trade))->reason)->toBe(ChatDeliveryRefusal::TradeNotNegotiating);
    });

    it('refuses a trade without an offer', function () {
        $trade = filledChatTrade();
        DB::table('trade_offers')->where('trade_id', $trade->id)->delete();

        expect(chatDeliverRefusal(fn () => deliverFromChat($trade))->reason)->toBe(ChatDeliveryRefusal::NoOffer);
    });

    it('answers null for the number of another supplier', function () {
        $trade = filledChatTrade();

        expect(deliverFromChat($trade, ChatDeliveryFactory::OTHER_STEAM_ID))->toBeNull()
            ->and($trade->fresh()->delivered_at)->toBeNull();
    });
});
