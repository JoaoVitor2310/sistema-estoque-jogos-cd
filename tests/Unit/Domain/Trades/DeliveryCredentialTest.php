<?php

/*
|--------------------------------------------------------------------------
| DeliveryCredentialTest — o segredo que protege a entrega de uma trade
|--------------------------------------------------------------------------
|
| Casos testados:
|
|   Geração:
|     1. tem o comprimento e os grupos definidos pelas constantes
|     2. usa só o alfabeto Crockford (sem I, L, O, U)
|     3. dois tokens seguidos são diferentes
|
|   Emissão (o par que toda trade recebe ao nascer):
|     4. devolve exatamente as duas colunas da credencial
|     5. o endereço é um UUID v4
|     6. duas emissões não coincidem em nenhuma das duas partes
|
|   Normalização (o token é colado no caso normal e digitado na exceção):
|     7. minúsculas viram maiúsculas
|     8. traços e espaços são ignorados
|     9. O vira 0, I e L viram 1
|
|   Comparação:
|    10. token correto confere, em qualquer grafia aceita
|    11. token errado não confere
|    12. sem token guardado, nada confere
|
|   Marca (o que amarra a sessão do supplier à credencial vigente):
|    13. tamanho fixo, sem carregar o token
|    14. a mesma para todas as grafias aceitas
|    15. diferente para tokens diferentes
|
*/

use App\Domain\Trades\DeliveryCredential;

describe('DeliveryCredential::generate', function () {

    it('produces a token with the configured length and grouping', function () {
        $token = DeliveryCredential::generate();

        $groups = explode('-', $token);

        expect($groups)->toHaveCount(DeliveryCredential::TOKEN_LENGTH / DeliveryCredential::TOKEN_GROUP_SIZE);
        expect(str_replace('-', '', $token))->toHaveLength(DeliveryCredential::TOKEN_LENGTH);

        foreach ($groups as $group) {
            expect($group)->toHaveLength(DeliveryCredential::TOKEN_GROUP_SIZE);
        }
    });

    it('uses only the unambiguous Crockford alphabet', function () {
        // 200 tokens: com 16 caracteres cada, a chance de um alfabeto errado
        // passar despercebido numa amostra dessas é desprezível.
        for ($i = 0; $i < 200; $i++) {
            $raw = str_replace('-', '', DeliveryCredential::generate());

            expect(strspn($raw, DeliveryCredential::TOKEN_ALPHABET))->toBe(strlen($raw));
        }
    });

    it('does not repeat itself', function () {
        expect(DeliveryCredential::generate())->not->toBe(DeliveryCredential::generate());
    });
});

describe('DeliveryCredential::issue', function () {

    it('returns exactly the two credential columns', function () {
        expect(array_keys(DeliveryCredential::issue()))
            ->toBe(['delivery_uuid', 'delivery_token']);
    });

    it('addresses the delivery with a version 4 uuid', function () {
        // v4 e não sequencial: o endereço é público e não pode deixar adivinhar
        // a entrega vizinha.
        expect(DeliveryCredential::issue()['delivery_uuid'])
            ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
    });

    it('never issues the same address or secret twice', function () {
        // Um par repetido daria a um supplier a entrega de outro.
        $first = DeliveryCredential::issue();
        $second = DeliveryCredential::issue();

        expect($first['delivery_uuid'])->not->toBe($second['delivery_uuid'])
            ->and($first['delivery_token'])->not->toBe($second['delivery_token']);
    });
});

describe('DeliveryCredential::normalize', function () {

    it('uppercases what the supplier typed', function () {
        expect(DeliveryCredential::normalize('abcd'))->toBe('ABCD');
    });

    it('ignores the separators used to display the token', function () {
        expect(DeliveryCredential::normalize('AB CD-EF  GH'))->toBe('ABCDEFGH');
    });

    it('folds the characters Crockford drops onto their digits', function () {
        // É o que torna verdadeira a promessa de digitar no celular sem errar.
        expect(DeliveryCredential::normalize('OoIiLl'))->toBe('001111');
    });
});

describe('DeliveryCredential::matches', function () {

    it('accepts the right token in any accepted spelling', function () {
        $stored = DeliveryCredential::generate();

        expect(DeliveryCredential::matches($stored, $stored))->toBeTrue();
        expect(DeliveryCredential::matches(strtolower($stored), $stored))->toBeTrue();
        expect(DeliveryCredential::matches(str_replace('-', '', $stored), $stored))->toBeTrue();
        expect(DeliveryCredential::matches(str_replace('-', ' ', $stored), $stored))->toBeTrue();
    });

    it('rejects the wrong token', function () {
        $stored = DeliveryCredential::generate();

        expect(DeliveryCredential::matches(DeliveryCredential::generate(), $stored))->toBeFalse();
    });

    it('rejects anything when there is no token stored', function () {
        expect(DeliveryCredential::matches(DeliveryCredential::generate(), null))->toBeFalse();
    });
});

describe('DeliveryCredential::fingerprint', function () {

    it('produces a fixed-width digest that does not carry the token', function () {
        $token = DeliveryCredential::generate();
        $fingerprint = DeliveryCredential::fingerprint($token);

        expect($fingerprint)->toHaveLength(64)
            ->and($fingerprint)->not->toContain(str_replace('-', '', $token));
    });

    it('is the same for every accepted spelling of the same token', function () {
        // A sessão guarda a marca do token guardado; a comparação não pode
        // depender da grafia com que ele foi lido.
        $token = DeliveryCredential::generate();

        expect(DeliveryCredential::fingerprint(strtolower($token)))
            ->toBe(DeliveryCredential::fingerprint($token));
    });

    it('changes when the token is rotated', function () {
        // É o que faz a rotação derrubar a sessão já aberta.
        expect(DeliveryCredential::fingerprint(DeliveryCredential::generate()))
            ->not->toBe(DeliveryCredential::fingerprint(DeliveryCredential::generate()));
    });
});
