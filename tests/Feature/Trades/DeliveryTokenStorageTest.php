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
});
