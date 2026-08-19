<?php

use App\UseCases\Trades\CreateTradeUseCase;

describe('CreateTradeUseCase', function () {

    it('persists tf2_qty as provided', function () {
        $trade = app(CreateTradeUseCase::class)->execute([
            'tf2Qty' => '12.5',
        ]);

        expect($trade->tf2_qty)->toBe('12.50');
    });

    it('stores null tf2_qty when not provided', function () {
        $trade = app(CreateTradeUseCase::class)->execute([]);

        expect($trade->tf2_qty)->toBeNull();
    });

    it('is born with a delivery credential', function () {
        // Sem ela a trade chega na aba sem link e sem código para copiar.
        $trade = app(CreateTradeUseCase::class)->execute([]);

        expect($trade->delivery_uuid)->not->toBeNull()
            ->and($trade->delivery_token)->not->toBeNull();
    });

    it('seeds one blank line so the card is editable right away', function () {
        $trade = app(CreateTradeUseCase::class)->execute([]);

        $line = $trade->lines->first();

        expect($trade->lines)->toHaveCount(1)
            ->and($line->position)->toBe(0)
            ->and($line->game_name)->toBeNull()
            ->and($line->market_price)->toBeNull()
            ->and($line->key_code)->toBeNull();
    });
});
