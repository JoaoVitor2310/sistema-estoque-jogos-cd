<?php

use App\Domain\Pricing\CounterOffer;

/** Linha da contraproposta, em centavos. */
function counterLine(int $current, ?int $target): array
{
    return ['current' => $current, 'target' => $target];
}

describe('CounterOffer::limits()', function () {

    it('limits each game to its round target, never below what the supplier already read', function () {
        expect(CounterOffer::limits([
            counterLine(100, 150),
            // A cotação mudou e o alvo caiu abaixo do vigente: mantém o vigente
            counterLine(100, 80),
            // Sem como recalcular: mantém o vigente
            counterLine(100, null),
        ]))->toBe([150, 100, 100]);
    });
});

describe('CounterOffer::canConcede()', function () {

    it('concedes only while some game can still go up', function () {
        expect(CounterOffer::canConcede([counterLine(100, 100), counterLine(200, 201)]))->toBeTrue()
            ->and(CounterOffer::canConcede([counterLine(100, 100), counterLine(200, 150)]))->toBeFalse()
            ->and(CounterOffer::canConcede([counterLine(100, null)]))->toBeFalse();
    });
});

describe('CounterOffer::prices()', function () {

    it('gives every game its limit when the request reaches their sum', function () {
        expect(CounterOffer::prices([counterLine(100, 200), counterLine(300, 600)], 999))->toBe([200, 600])
            ->and(CounterOffer::prices([counterLine(100, 200), counterLine(300, 600)], 800))->toBe([200, 600]);
    });

    it('raises every game in the same proportion to meet the request exactly', function () {
        // 600 / 400 = 1,5 sobre o vigente
        expect(CounterOffer::prices([counterLine(100, 200), counterLine(300, 600)], 600))->toBe([150, 450]);
    });

    it('stops a game at its limit and shares the rest among the others', function () {
        // A 1,5 o primeiro passaria de 120: para em 120, e o segundo leva 480
        expect(CounterOffer::prices([counterLine(100, 120), counterLine(300, 600)], 600))->toBe([120, 480]);
    });

    it('closes the total to the cent', function () {
        // 400 / 3 = 133,33…: o centavo que sobra vai para um dos jogos
        $prices = CounterOffer::prices([counterLine(100, 1000), counterLine(100, 1000), counterLine(100, 1000)], 400);

        expect(array_sum($prices))->toBe(400)
            ->and($prices)->toBe([134, 133, 133]);
    });

    it('never rounds a game above its limit', function () {
        // 299 / 200 = 1,495 → 149,5 cada; o centavo que falta não pode passar de 150
        $prices = CounterOffer::prices([counterLine(100, 150), counterLine(100, 150)], 299);

        expect(array_sum($prices))->toBe(299)
            ->and(max($prices))->toBeLessThanOrEqual(150);
    });

    it('never lowers a game below what the supplier already read', function () {
        $prices = CounterOffer::prices([counterLine(100, 100), counterLine(100, 300)], 250);

        expect($prices)->toBe([100, 150]);
    });

    it('refuses a request that is not above the current total', function (int $requested) {
        // Distribuir 300 sobre 400 vigentes daria [75, 225]: abaixo do que ele leu
        expect(fn () => CounterOffer::prices([counterLine(100, 200), counterLine(300, 600)], $requested))
            ->toThrow(InvalidArgumentException::class);
    })->with(['below' => [300], 'equal' => [400]]);
});
