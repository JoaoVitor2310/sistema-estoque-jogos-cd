<?php

/*
|--------------------------------------------------------------------------
| TradeLineAuthorityTest — quem escreve, e como o que ele escreveu é lido
|--------------------------------------------------------------------------
|
| Casos testados:
|
|   Escopo de escrita:
|     1. a equipe alcança todas as colunas da linha
|     2. o supplier alcança só os campos da entrega, bundle incluído
|     3. o supplier nunca alcança o que precifica a oferta
|
|   Formato de data:
|     4. a equipe escreve dd/mm/aaaa
|     5. o supplier escreve mm/dd/aaaa
|     6. a mesma string vira datas diferentes conforme quem escreveu
|
*/

use App\Domain\Enums\TradeLineAuthority;
use App\Domain\Trades\TradeLineValue;

describe('TradeLineAuthority::writableColumns', function () {

    it('lets the team reach every column of a line', function () {
        expect(TradeLineAuthority::Team->writableColumns())
            ->toContain('game_name', 'market_price', 'popularity', 'bundle', 'gamivo_id');
    });

    it('limits the supplier to the delivery fields', function () {
        // `bundle` entrou em 2026-08-19: chega pré-preenchido pela nossa busca,
        // e quem teve a key na mão corrige de onde ela veio.
        expect(TradeLineAuthority::Supplier->writableColumns())
            ->toBe(['region', 'bundle', 'expires_at', 'key_code']);
    });

    it('never lets the supplier reach the researched price', function () {
        // É quanto o jogo dele vale para nós — visto uma vez, renegocia toda
        // trade futura com o número na mão.
        expect(TradeLineAuthority::Supplier->writableColumns())
            ->not->toContain('market_price', 'popularity', 'gamivo_id');
    });
});

describe('TradeLineAuthority::dateFormat', function () {

    it('reads the team side as day first', function () {
        expect(TradeLineValue::date('02/06/2027', TradeLineAuthority::Team->dateFormat()))
            ->toBe('2027-06-02');
    });

    it('reads the supplier side as month first', function () {
        // A página da entrega é em inglês.
        expect(TradeLineValue::date('02/06/2027', TradeLineAuthority::Supplier->dateFormat()))
            ->toBe('2027-02-06');
    });

    it('still rejects an impossible date on either side', function () {
        expect(TradeLineValue::date('02/31/2027', TradeLineAuthority::Supplier->dateFormat()))->toBeNull()
            ->and(TradeLineValue::date('31/02/2027', TradeLineAuthority::Team->dateFormat()))->toBeNull();
    });

    it('reads the iso form the database returns on either side', function () {
        expect(TradeLineValue::date('2027-06-02', TradeLineAuthority::Supplier->dateFormat()))
            ->toBe('2027-06-02')
            ->and(TradeLineValue::date('2027-06-02', TradeLineAuthority::Team->dateFormat()))
            ->toBe('2027-06-02');
    });
});
