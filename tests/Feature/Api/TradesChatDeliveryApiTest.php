<?php

/*
|--------------------------------------------------------------------------
| PATCH /api/suppliers/{steamId}/trades/{number}/keys e POST .../deliver — HTTP
|--------------------------------------------------------------------------
|
| A borda da entrega pelo chat: autenticação, validação, o código de cada
| recusa e o formato da resposta — e que a key nunca volta. As regras estão
| em FillTradeKeysFromChatUseCaseTest e DeliverTradeFromChatUseCaseTest.
|
*/

use App\Models\Trade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\ChatDeliveryFactory;

const CHAT_DELIVERY_API_SECRET = 'test-chat-delivery-api-secret';

function chatKeysUri(Trade $trade, string $steamId = ChatDeliveryFactory::STEAM_ID): string
{
    return "/api/suppliers/{$steamId}/trades/{$trade->supplier_trade_number}/keys";
}

function chatDeliverUri(Trade $trade, string $steamId = ChatDeliveryFactory::STEAM_ID): string
{
    return "/api/suppliers/{$steamId}/trades/{$trade->supplier_trade_number}/deliver";
}

/** @param  array<int, string>  $keys  key por id da linha */
function patchChatKeys(Trade $trade, array $keys)
{
    $pairs = [];
    foreach ($keys as $lineId => $keyCode) {
        $pairs[] = ['line_id' => $lineId, 'key_code' => $keyCode];
    }

    return test()->withToken(CHAT_DELIVERY_API_SECRET)->patchJson(chatKeysUri($trade), ['keys' => $pairs]);
}

/** @return list<array{line_id: int, key_code: string}> pares com linhas que não existem */
function chatKeyPairs(int $count): array
{
    return array_map(fn (int $i) => ['line_id' => 100000 + $i, 'key_code' => "KEY-{$i}"], range(1, $count));
}

beforeEach(function () {
    Config::set('services.chatbot_api_secret', CHAT_DELIVERY_API_SECRET);
    Config::set('services.external_secret', 'the-price-researcher-secret');
    ChatDeliveryFactory::seedDependencies();
    Mail::fake();
});

describe('PATCH /api/suppliers/{steamId}/trades/{number}/keys', function () {

    it('answers 200 with the offer lines still without a key', function () {
        $trade = ChatDeliveryFactory::trade();
        $lines = ChatDeliveryFactory::lines($trade);

        patchChatKeys($trade, [$lines['Half-Life']->id => 'AAAAA-BBBBB-CCCCC'])
            ->assertStatus(200)
            ->assertExactJson(['missing_line_ids' => [$lines['Portal']->id]]);
    });

    it('answers an empty list once every line has a key, and never echoes a key back', function () {
        $trade = ChatDeliveryFactory::trade();

        $response = patchChatKeys($trade, ChatDeliveryFactory::allKeys($trade))
            ->assertStatus(200)
            ->assertExactJson(['missing_line_ids' => []]);

        expect($response->getContent())->not->toContain('AAAAA-BBBBB-CCCCC');
    });

    it('trims the key the supplier pasted', function () {
        $trade = ChatDeliveryFactory::trade();
        $portal = ChatDeliveryFactory::lines($trade)['Portal'];

        patchChatKeys($trade, [$portal->id => '  DDDDD-EEEEE-FFFFF  '])->assertStatus(200);

        expect($portal->fresh()->key_code)->toBe('DDDDD-EEEEE-FFFFF');
    });

    it('answers 422 with the lines that are not in the offer', function () {
        $trade = ChatDeliveryFactory::trade();

        patchChatKeys($trade, [999999 => 'XXXXX-YYYYY-ZZZZZ'])
            ->assertStatus(422)
            ->assertExactJson(['reason' => 'line_not_in_offer', 'line_ids' => [999999]]);
    });

    it('answers 409 once the trade was delivered', function () {
        $trade = ChatDeliveryFactory::trade();
        DB::table('trades')->where('id', $trade->id)->update(['delivered_at' => now()]);

        patchChatKeys($trade, ChatDeliveryFactory::allKeys($trade))
            ->assertStatus(409)
            ->assertExactJson(['reason' => 'trade_not_negotiating']);
    });

    it('answers 404 for a number of another supplier', function () {
        $trade = ChatDeliveryFactory::trade();

        $this->withToken(CHAT_DELIVERY_API_SECRET)
            ->patchJson(chatKeysUri($trade, ChatDeliveryFactory::OTHER_STEAM_ID), ['keys' => [['line_id' => 1, 'key_code' => 'AAAAA']]])
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Trade not found.']);
    });

    it('validates the pairs', function (array $body, string $field) {
        $trade = ChatDeliveryFactory::trade();

        $this->withToken(CHAT_DELIVERY_API_SECRET)
            ->patchJson(chatKeysUri($trade), $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    })->with([
        'no keys' => [[], 'keys'],
        'empty list' => [['keys' => []], 'keys'],
        'blank key' => [['keys' => [['line_id' => 1, 'key_code' => '   ']]], 'keys.0.key_code'],
        'line repeated' => [['keys' => [['line_id' => 1, 'key_code' => 'A'], ['line_id' => 1, 'key_code' => 'B']]], 'keys.0.line_id'],
        'line not a number' => [['keys' => [['line_id' => 'abc', 'key_code' => 'A']]], 'keys.0.line_id'],
        // Pelo chat vem só a key; região e validade ficam com a equipe.
        'region along' => [['keys' => [['line_id' => 1, 'key_code' => 'A', 'region' => 'EU']]], 'keys.0'],
        // Mais que um bloco do modelo (75 jogos) não veio de uma mensagem.
        'more than one block' => [['keys' => chatKeyPairs(76)], 'keys'],
    ]);

    it('accepts a full block of the template', function () {
        $trade = ChatDeliveryFactory::trade();

        // As linhas não são da oferta: o pedido passa da validação e cai na recusa.
        $this->withToken(CHAT_DELIVERY_API_SECRET)
            ->patchJson(chatKeysUri($trade), ['keys' => chatKeyPairs(75)])
            ->assertStatus(422)
            ->assertJsonMissingValidationErrors('keys')
            ->assertJsonPath('reason', 'line_not_in_offer');
    });
});

describe('POST /api/suppliers/{steamId}/trades/{number}/deliver', function () {

    it('answers 200 with the trade, now awaiting review, and the offer total as the amount', function () {
        $trade = ChatDeliveryFactory::trade();
        patchChatKeys($trade, ChatDeliveryFactory::allKeys($trade));

        $response = $this->withToken(CHAT_DELIVERY_API_SECRET)->postJson(chatDeliverUri($trade))->assertStatus(200);

        expect($response->json('uuid'))->toBe($trade->uuid)
            ->and($response->json('state'))->toBe('awaiting_review')
            ->and($response->getContent())->not->toContain('AAAAA-BBBBB-CCCCC')
            ->and($trade->fresh()->amount)->toBe('5.80');
    });

    it('answers 409 with the lines still without a key', function () {
        $trade = ChatDeliveryFactory::trade();
        $lines = ChatDeliveryFactory::lines($trade);
        patchChatKeys($trade, [$lines['Half-Life']->id => 'AAAAA-BBBBB-CCCCC']);

        $this->withToken(CHAT_DELIVERY_API_SECRET)->postJson(chatDeliverUri($trade))
            ->assertStatus(409)
            ->assertExactJson(['reason' => 'missing_keys', 'line_ids' => [$lines['Portal']->id]]);
    });

    it('answers a repeated confirmation with its own 409', function () {
        $trade = ChatDeliveryFactory::trade();
        patchChatKeys($trade, ChatDeliveryFactory::allKeys($trade));
        $this->withToken(CHAT_DELIVERY_API_SECRET)->postJson(chatDeliverUri($trade))->assertStatus(200);

        $this->withToken(CHAT_DELIVERY_API_SECRET)->postJson(chatDeliverUri($trade))
            ->assertStatus(409)
            ->assertExactJson(['reason' => 'already_delivered']);
    });

    it('answers 404 for a number the supplier does not have', function () {
        ChatDeliveryFactory::trade();

        $this->withToken(CHAT_DELIVERY_API_SECRET)
            ->postJson('/api/suppliers/'.ChatDeliveryFactory::STEAM_ID.'/trades/99/deliver')
            ->assertStatus(404);
    });
});
