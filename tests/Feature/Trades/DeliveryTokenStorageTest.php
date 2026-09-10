<?php

/*
|--------------------------------------------------------------------------
| O token de entrega na tabela trades
|--------------------------------------------------------------------------
|
| Não pertence a nenhum UseCase: é a forma como a coluna é guardada — cast
| `encrypted` na escrita, texto legível na leitura, porque o par fica à vista
| na aba para a equipe copiar.
|
*/

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Tests\Support\DeliveryFactory;

describe('The delivery token in the trades table', function () {

    it('is stored encrypted and read back through the cast', function () {
        // Encriptado na coluna porque não precisa estar em claro lá; legível de
        // volta porque o par fica à vista na aba para a equipe copiar.
        [$trade, $token] = DeliveryFactory::tradeWithCredential();

        $raw = DB::table('trades')->where('id', $trade->id)->value('delivery_token');

        expect($trade->delivery_token)->toBe($token)
            ->and($raw)->not->toContain($token);
    });

    it('reads back as null when the stored value does not open with the current key', function () {
        // O caso real: um dump de outro ambiente aberto aqui, ou a APP_KEY
        // rotacionada. O cast lança na leitura, e o token é lido para toda
        // trade listada — sem esta tolerância, uma linha ilegível derrubava a
        // aba inteira com 500 em vez de deixar só ela sem código.
        [$trade] = DeliveryFactory::tradeWithCredential();

        $foreign = new Encrypter(Encrypter::generateKey('aes-256-cbc'), 'aes-256-cbc');

        DB::table('trades')->where('id', $trade->id)->update([
            'delivery_token' => $foreign->encryptString('ABCD-EFGH-JKMN-PQRS'),
        ]);

        expect($trade->fresh()->readableDeliveryToken())->toBeNull();
    });
});
