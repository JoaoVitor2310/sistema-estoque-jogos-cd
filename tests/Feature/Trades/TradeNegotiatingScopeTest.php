<?php

/*
|--------------------------------------------------------------------------
| Trade::scopeNegotiating — a mesma régua de TradeDeliveryState
|--------------------------------------------------------------------------
|
| "Em negociação" existe em PHP (TradeDeliveryState::resolve) e em SQL
| (o scope, para as listas). Este teste amarra as duas: se uma mudar sem a
| outra, uma trade aparece numa lista com um estado que não é o dela.
|
*/

use App\Domain\Enums\TradeDeliveryState;
use App\Models\Trade;
use Tests\Support\TradeFactory;

it('selects exactly the trades whose delivery state is negotiating', function () {
    // Toda combinação das duas colunas que derivam o estado
    $trades = collect([
        ['delivered_at' => null, 'is_imported' => false],
        ['delivered_at' => now(), 'is_imported' => false],
        ['delivered_at' => now(), 'is_imported' => true],
        ['delivered_at' => null, 'is_imported' => true],
    ])->map(fn (array $attrs) => TradeFactory::withLines([], $attrs));

    $expected = $trades
        ->filter(fn (Trade $trade) => $trade->deliveryState() === TradeDeliveryState::Negotiating)
        ->pluck('id')->sort()->values()->all();

    expect(Trade::negotiating()->orderBy('id')->pluck('id')->all())->toBe($expected)
        ->and($expected)->toHaveCount(1);
});
