<?php

/*
|--------------------------------------------------------------------------
| DeleteTradeLineUseCase — orquestração
|--------------------------------------------------------------------------
|
| O que a rota não cobre e vive aqui: o fechamento do buraco deixado na ordem,
| do qual a tela depende para endereçar "logo abaixo desta" pelo índice.
|
*/

use App\Models\TradeLine;
use App\UseCases\Trades\CreateTradeLineUseCase;
use App\UseCases\Trades\DeleteTradeLineUseCase;
use Tests\Support\TradeFactory;
use Tests\Support\TradeLineFactory;

describe('DeleteTradeLineUseCase', function () {

    it('removes the line', function () {
        $trade = TradeFactory::withLines(['A']);
        $line = $trade->lines->first();

        app(DeleteTradeLineUseCase::class)->execute($line);

        expect(TradeLine::find($line->id))->toBeNull();
    });

    it('closes the gap left in the order', function () {
        $trade = TradeFactory::withLines(['A', 'B', 'C']);

        app(DeleteTradeLineUseCase::class)->execute($trade->lines[1]);

        expect($trade->fresh()->lines->pluck('game_name')->all())->toBe(['A', 'C'])
            ->and($trade->fresh()->lines->pluck('position')->all())->toBe([0, 1]);
    });

    it('keeps positions contiguous after deleting the first line', function () {
        $trade = TradeFactory::withLines(['A', 'B', 'C']);

        app(DeleteTradeLineUseCase::class)->execute($trade->lines->first());

        expect($trade->fresh()->lines->pluck('position')->all())->toBe([0, 1]);
    });

    it('leaves an append landing on the freed position', function () {
        // Se o buraco não fechasse, o próximo append pularia um número e a tela
        // deixaria de conseguir endereçar "logo abaixo desta" pelo índice.
        $trade = TradeFactory::withLines(['A', 'B', 'C']);

        app(DeleteTradeLineUseCase::class)->execute($trade->lines->last());
        $appended = app(CreateTradeLineUseCase::class)->execute($trade, TradeLineFactory::dto(['game_name' => 'D']));

        expect($appended->position)->toBe(2);
    });

    it('does not renumber the lines of another trade', function () {
        $other = TradeFactory::withLines(['X', 'Y', 'Z']);
        $trade = TradeFactory::withLines(['A', 'B', 'C']);

        app(DeleteTradeLineUseCase::class)->execute($trade->lines->first());

        expect($other->fresh()->lines->pluck('position')->all())->toBe([0, 1, 2])
            ->and($other->fresh()->lines->pluck('game_name')->all())->toBe(['X', 'Y', 'Z']);
    });

    it('empties a trade down to no lines at all', function () {
        $trade = TradeFactory::withLines(['A']);

        app(DeleteTradeLineUseCase::class)->execute($trade->lines->first());

        expect($trade->fresh()->lines)->toBeEmpty();
    });
});
