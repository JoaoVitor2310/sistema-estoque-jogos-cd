<?php

/*
|--------------------------------------------------------------------------
| SubmitTradeDeliveryUseCase — orquestração
|--------------------------------------------------------------------------
|
| O que a rota não cobre e vive aqui: a semântica de patch parcial dos campos
| que o supplier preenche, e o limite do que ele consegue alcançar na trade.
|
*/

use App\UseCases\Trades\SubmitTradeDeliveryUseCase;
use Tests\Support\DeliveryFactory;
use Tests\Support\TradeFactory;

describe('SubmitTradeDeliveryUseCase — partial patch', function () {

    it('changes only the columns the payload carried', function () {
        $trade = TradeFactory::withLines(['Portal'], ['tf2_qty' => '5.00', 'supplier_notes' => 'kept']);

        app(SubmitTradeDeliveryUseCase::class)->execute($trade, DeliveryFactory::dto(['tf2_qty' => '9']));

        $trade->refresh();

        expect($trade->tf2_qty)->toBe('9.00')
            ->and($trade->supplier_notes)->toBe('kept');
    });

    it('clears a column that was sent empty', function () {
        $trade = TradeFactory::withLines(['Portal'], ['supplier_notes' => 'old']);

        app(SubmitTradeDeliveryUseCase::class)->execute($trade, DeliveryFactory::dto(['supplier_notes' => '']));

        expect($trade->fresh()->supplier_notes)->toBeNull();
    });

    it('leaves the trade untouched when the payload carries nothing', function () {
        $trade = TradeFactory::withLines(['Portal'], ['tf2_qty' => '5.00']);

        app(SubmitTradeDeliveryUseCase::class)->execute($trade, DeliveryFactory::dto());

        expect($trade->fresh()->tf2_qty)->toBe('5.00');
    });

    it('cannot reach any other column of the trade', function () {
        // O escopo do supplier é a forma do DTO — ele não sabe carregar outra
        // coluna, então nem chega a haver o que filtrar.
        $trade = TradeFactory::withLines(['Portal'], ['title' => 'internal note', 'is_imported' => false]);

        app(SubmitTradeDeliveryUseCase::class)->execute($trade, DeliveryFactory::dto([
            'tf2_qty' => '9',
            'title' => 'hijacked',
            'is_imported' => true,
        ]));

        $trade->refresh();

        expect($trade->title)->toBe('internal note')
            ->and($trade->is_imported)->toBeFalse();
    });
});
