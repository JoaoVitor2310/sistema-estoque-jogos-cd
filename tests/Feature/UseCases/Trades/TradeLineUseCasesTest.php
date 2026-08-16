<?php

/*
|--------------------------------------------------------------------------
| Create / Update / DeleteTradeLineUseCase — orquestração
|--------------------------------------------------------------------------
|
| O que as rotas não cobrem e vive aqui: a manutenção das posições (empurrar
| ao inserir, fechar o buraco ao remover) e a semântica de patch parcial, que
| é o contrato do qual a entrega pelo supplier vai depender.
|
*/

use App\Models\TradeLine;
use App\UseCases\Trades\CreateTradeLineUseCase;
use App\UseCases\Trades\DeleteTradeLineUseCase;
use App\UseCases\Trades\DTO\TradeLineDTO;
use App\UseCases\Trades\UpdateTradeLineUseCase;
use Tests\Support\TradeFactory;

/** @param array<string, mixed> $payload */
function lineDto(array $payload = []): TradeLineDTO
{
    return TradeLineDTO::fromValidated($payload);
}

describe('CreateTradeLineUseCase — position', function () {

    it('appends to the end when no position is given', function () {
        $trade = TradeFactory::withLines(['A', 'B']);

        $line = app(CreateTradeLineUseCase::class)->execute($trade, lineDto(['game_name' => 'C']));

        expect($line->position)->toBe(2)
            ->and($trade->fresh()->lines->pluck('game_name')->all())->toBe(['A', 'B', 'C']);
    });

    it('starts at zero on a trade with no lines', function () {
        $trade = TradeFactory::withLines([]);

        expect(app(CreateTradeLineUseCase::class)->execute($trade, lineDto())->position)->toBe(0);
    });

    it('pushes the following lines down when inserting in the middle', function () {
        $trade = TradeFactory::withLines(['A', 'B', 'C']);

        app(CreateTradeLineUseCase::class)->execute($trade, lineDto([
            'game_name' => 'A copy',
            'position' => 1,
        ]));

        expect($trade->fresh()->lines->pluck('game_name')->all())
            ->toBe(['A', 'A copy', 'B', 'C'])
            ->and($trade->fresh()->lines->pluck('position')->all())->toBe([0, 1, 2, 3]);
    });

    it('inserting at the front pushes everything down', function () {
        $trade = TradeFactory::withLines(['A', 'B']);

        app(CreateTradeLineUseCase::class)->execute($trade, lineDto(['game_name' => 'Z', 'position' => 0]));

        expect($trade->fresh()->lines->pluck('game_name')->all())->toBe(['Z', 'A', 'B']);
    });

    it('appends correctly right after a middle insert', function () {
        // Se o empurrão deixasse posição repetida, o append seguinte cairia em
        // cima de uma linha existente.
        $trade = TradeFactory::withLines(['A', 'B']);
        $create = app(CreateTradeLineUseCase::class);

        $create->execute($trade, lineDto(['game_name' => 'A copy', 'position' => 1]));
        $appended = $create->execute($trade, lineDto(['game_name' => 'D']));

        expect($appended->position)->toBe(3)
            ->and($trade->fresh()->lines->pluck('game_name')->all())->toBe(['A', 'A copy', 'B', 'D']);
    });

    it('clamps a position past the end instead of leaving a gap', function () {
        // Buraco na numeração quebraria a contiguidade de que a tela depende
        // para espelhar o empurrão do servidor sem reconsultar o banco.
        $trade = TradeFactory::withLines(['A', 'B']);

        $line = app(CreateTradeLineUseCase::class)->execute($trade, lineDto([
            'game_name' => 'C',
            'position' => 99,
        ]));

        expect($line->position)->toBe(2)
            ->and($trade->fresh()->lines->pluck('position')->all())->toBe([0, 1, 2]);
    });

    it('does not touch the lines of another trade when inserting', function () {
        $other = TradeFactory::withLines(['X', 'Y']);
        $trade = TradeFactory::withLines(['A', 'B']);

        app(CreateTradeLineUseCase::class)->execute($trade, lineDto(['game_name' => 'New', 'position' => 0]));

        expect($other->fresh()->lines->pluck('position')->all())->toBe([0, 1]);
    });

    it('links the line to the trade it was created on', function () {
        $trade = TradeFactory::withLines([]);

        expect(app(CreateTradeLineUseCase::class)->execute($trade, lineDto())->trade_id)->toBe($trade->id);
    });

    it('creates a fully blank line when the payload is empty', function () {
        $trade = TradeFactory::withLines([]);

        $line = app(CreateTradeLineUseCase::class)->execute($trade, lineDto());

        expect($line->game_name)->toBeNull()
            ->and($line->market_price)->toBeNull()
            ->and($line->popularity)->toBeNull()
            ->and($line->key_code)->toBeNull()
            ->and($line->expires_at)->toBeNull();
    });

    it('persists the fields it was given, normalised', function () {
        $trade = TradeFactory::withLines([]);

        $line = app(CreateTradeLineUseCase::class)->execute($trade, lineDto([
            'game_name' => '  Portal  ',
            'market_price' => '3.00',
            'popularity' => '120',
            'expires_at' => '02/06/2027',
        ]));

        expect($line->game_name)->toBe('Portal')
            ->and($line->market_price)->toBe('3.00')
            ->and($line->popularity)->toBe(120)
            ->and($line->expires_at->format('Y-m-d'))->toBe('2027-06-02');
    });
});

describe('UpdateTradeLineUseCase — partial patch', function () {

    it('changes only the columns the payload carried', function () {
        $trade = TradeFactory::withLines([
            ['game_name' => 'Portal', 'market_price' => '3.00', 'region' => 'EU', 'popularity' => 500],
        ]);
        $line = $trade->lines->first();

        app(UpdateTradeLineUseCase::class)->execute($line, lineDto(['key_code' => 'AAA-BBB']));

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

        app(UpdateTradeLineUseCase::class)->execute($line, lineDto(['region' => '']));

        expect($line->refresh()->region)->toBeNull();
    });

    it('leaves the line untouched when the payload carries nothing', function () {
        $trade = TradeFactory::withLines([['game_name' => 'Portal', 'region' => 'EU']]);
        $line = $trade->lines->first();

        app(UpdateTradeLineUseCase::class)->execute($line, lineDto());

        $line->refresh();

        expect($line->game_name)->toBe('Portal')
            ->and($line->region)->toBe('EU');
    });

    it('never moves the line, even when the DTO carries a position', function () {
        // `position` sai fora dos atributos de propósito: reordenar não é
        // edição de campo. A rota já recusa, mas o UseCase não depende disso.
        $trade = TradeFactory::withLines(['A', 'B']);
        $first = $trade->lines->first();

        app(UpdateTradeLineUseCase::class)->execute($first, lineDto(['position' => 1, 'key_code' => 'AAA']));

        expect($first->refresh()->position)->toBe(0)
            ->and($first->key_code)->toBe('AAA');
    });

    it('does not touch the sibling lines', function () {
        $trade = TradeFactory::withLines(['Portal', 'Half-Life']);

        app(UpdateTradeLineUseCase::class)->execute(
            $trade->lines->first(),
            lineDto(['game_name' => 'Portal 2']),
        );

        expect($trade->fresh()->lines->pluck('game_name')->all())->toBe(['Portal 2', 'Half-Life']);
    });
});

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
        $appended = app(CreateTradeLineUseCase::class)->execute($trade, lineDto(['game_name' => 'D']));

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
