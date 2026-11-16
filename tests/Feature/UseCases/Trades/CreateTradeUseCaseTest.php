<?php

use App\UseCases\Trades\CreateTradeUseCase;

describe('CreateTradeUseCase', function () {

    it('persists amount as provided', function () {
        $trade = app(CreateTradeUseCase::class)->execute([
            'amount' => '12.5',
        ]);

        expect($trade->amount)->toBe('12.50');
    });

    it('stores null amount when not provided', function () {
        $trade = app(CreateTradeUseCase::class)->execute([]);

        expect($trade->amount)->toBeNull();
    });

    it('is born with a delivery credential', function () {
        // Sem ela a trade chega na aba sem link e sem código para copiar.
        $trade = app(CreateTradeUseCase::class)->execute([]);

        expect($trade->uuid)->not->toBeNull()
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

describe('CreateTradeUseCase — supplier trade number', function () {

    it('numbers the trade within its supplier', function () {
        $url = 'https://steamcommunity.com/profiles/76561198000000601';

        $first = app(CreateTradeUseCase::class)->execute(['supplierUrl' => $url]);
        $second = app(CreateTradeUseCase::class)->execute(['supplierUrl' => $url]);

        expect($first->fresh()->supplier_trade_number)->toBe(1)
            ->and($second->fresh()->supplier_trade_number)->toBe(2);
    });

    it('gives no number to a trade without supplier', function () {
        expect(app(CreateTradeUseCase::class)->execute([])->fresh()->supplier_trade_number)->toBeNull();
    });
});
