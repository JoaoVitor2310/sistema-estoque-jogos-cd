<?php

/*
|--------------------------------------------------------------------------
| UpdateTradeLineUseCase — orquestração
|--------------------------------------------------------------------------
|
| O que a rota não cobre e vive aqui: a semântica de patch parcial (só o que
| o payload trouxe muda) e o escopo por autoridade — o filtro que impede o
| supplier de alcançar coluna que não é dele acontece aqui, não no Request.
|
*/

use App\Domain\Enums\TradeLineAuthority;
use App\UseCases\Trades\UpdateTradeLineUseCase;
use Tests\Support\TradeFactory;
use Tests\Support\TradeLineFactory;

describe('UpdateTradeLineUseCase — partial patch', function () {

    it('changes only the columns the payload carried', function () {
        $trade = TradeFactory::withLines([
            ['game_name' => 'Portal', 'market_price' => '3.00', 'region' => 'EU', 'popularity' => 500],
        ]);
        $line = $trade->lines->first();

        app(UpdateTradeLineUseCase::class)->execute($line, TradeLineFactory::dto(['key_code' => 'AAA-BBB']), TradeLineAuthority::Team);

        $line->refresh();

        expect($line->key_code)->toBe('AAA-BBB')
            ->and($line->game_name)->toBe('Portal')
            ->and($line->market_price)->toBe('3.00')
            ->and($line->region)->toBe('EU')
            ->and($line->popularity)->toBe(500);
    });

    it('clears a column that was sent empty', function () {
        $trade = TradeFactory::withLines([['game_name' => 'Portal', 'region' => 'EU']]);
        $line = $trade->lines->first();

        app(UpdateTradeLineUseCase::class)->execute($line, TradeLineFactory::dto(['region' => '']), TradeLineAuthority::Team);

        expect($line->refresh()->region)->toBeNull();
    });

    it('leaves the line untouched when the payload carries nothing', function () {
        $trade = TradeFactory::withLines([['game_name' => 'Portal', 'region' => 'EU']]);
        $line = $trade->lines->first();

        app(UpdateTradeLineUseCase::class)->execute($line, TradeLineFactory::dto(), TradeLineAuthority::Team);

        $line->refresh();

        expect($line->game_name)->toBe('Portal')
            ->and($line->region)->toBe('EU');
    });

    it('never moves the line, even when the DTO carries a position', function () {
        // `position` sai fora dos atributos de propósito: reordenar não é
        // edição de campo. A rota já recusa, mas o UseCase não depende disso.
        $trade = TradeFactory::withLines(['A', 'B']);
        $first = $trade->lines->first();

        app(UpdateTradeLineUseCase::class)->execute($first, TradeLineFactory::dto(['position' => 1, 'key_code' => 'AAA']), TradeLineAuthority::Team);

        expect($first->refresh()->position)->toBe(0)
            ->and($first->key_code)->toBe('AAA');
    });

    it('does not touch the sibling lines', function () {
        $trade = TradeFactory::withLines(['Portal', 'Half-Life']);

        app(UpdateTradeLineUseCase::class)->execute(
            $trade->lines->first(),
            TradeLineFactory::dto(['game_name' => 'Portal 2']),
            TradeLineAuthority::Team,
        );

        expect($trade->fresh()->lines->pluck('game_name')->all())->toBe(['Portal 2', 'Half-Life']);
    });
});

describe('UpdateTradeLineUseCase — authority scope', function () {

    it('writes the three delivery columns when the supplier is the author', function () {
        $trade = TradeFactory::withLines([['game_name' => 'Portal', 'market_price' => '3.00']]);
        $line = $trade->lines->first();

        app(UpdateTradeLineUseCase::class)->execute($line, TradeLineFactory::dto([
            'key_code' => 'AAA-BBB',
            'region' => 'EU',
            'expires_at' => '02/06/2027',
        ]), TradeLineAuthority::Supplier);

        $line->refresh();

        expect($line->key_code)->toBe('AAA-BBB')
            ->and($line->region)->toBe('EU')
            ->and($line->expires_at?->format('Y-m-d'))->toBe('2027-06-02');
    });

    it('drops every column outside the supplier scope, even when the payload carries it', function () {
        // O Form Request da entrega não aceita esses campos, mas o UseCase não
        // depende disso: é aqui que a recusa acontece.
        $trade = TradeFactory::withLines([
            ['game_name' => 'Portal', 'market_price' => '3.00', 'popularity' => 500, 'bundle' => 'Humble', 'gamivo_id' => '77'],
        ]);
        $line = $trade->lines->first();

        app(UpdateTradeLineUseCase::class)->execute($line, TradeLineFactory::dto([
            'game_name' => 'Outro jogo',
            'market_price' => '99.00',
            'popularity' => 1,
            'gamivo_id' => '999',
            'key_code' => 'AAA-BBB',
        ]), TradeLineAuthority::Supplier);

        $line->refresh();

        expect($line->key_code)->toBe('AAA-BBB')
            ->and($line->game_name)->toBe('Portal')
            ->and($line->market_price)->toBe('3.00')
            ->and($line->popularity)->toBe(500)
            ->and($line->gamivo_id)->toBe('77');
    });

    it('leaves the line untouched when the supplier sends only columns he cannot reach', function () {
        $trade = TradeFactory::withLines([['game_name' => 'Portal', 'market_price' => '3.00']]);
        $line = $trade->lines->first();

        app(UpdateTradeLineUseCase::class)->execute(
            $line,
            TradeLineFactory::dto(['market_price' => '99.00']),
            TradeLineAuthority::Supplier,
        );

        $line->refresh();

        expect($line->market_price)->toBe('3.00')
            ->and($line->game_name)->toBe('Portal');
    });
});
