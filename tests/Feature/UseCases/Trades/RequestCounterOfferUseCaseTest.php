<?php

/*
|--------------------------------------------------------------------------
| RequestCounterOfferUseCase — integration
|--------------------------------------------------------------------------
|
| A próxima rodada da oferta a partir do total que o supplier pediu: lucro-alvo
| descendo 10 pontos por rodada, nunca acima da oferta máxima nem abaixo do
| que ele já leu, até a 3ª, que é a final.
|
| Cenário: supplier Normal (70%), TF2 a 0,95 €, em TF2.
|   Half-Life 4,50 € → income 3,98 €  |  Portal 6,00 € → income 5,39 €
|   rodada 1 (70%): 2,46 + 3,34 = 5,80
|   rodada 2 (60%): 3,98/1,6/0,95 = 2,61 · 5,39/1,6/0,95 = 3,54 → 6,15
|   rodada 3 (50%): 3,98/1,5/0,95 = 2,79 · 5,39/1,5/0,95 = 3,78 → 6,57
|
*/

use App\Domain\Enums\CounterOfferRefusal;
use App\Domain\Enums\TradeCurrency;
use App\Domain\Pricing\CurrencyPriceUnavailable;
use App\Domain\Trades\CounterOfferRefused;
use App\Models\Trade;
use App\Models\TradeOffer;
use App\UseCases\Trades\CreateTradeWithOfferUseCase;
use App\UseCases\Trades\DTO\TradeWithOfferDTO;
use App\UseCases\Trades\RequestCounterOfferUseCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\TradeFactory;

function seedCounterDeps(float $tf2Euro = 0.95): void
{
    Cache::flush();

    DB::table('fees')->insertOrIgnore([
        ['name' => 'gamivo_percent_low', 'preco' => 0.06, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_low', 'preco' => 0.25, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_percent_high', 'preco' => 0.08, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_high', 'preco' => 0.40, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('assets')->insert([
        'name' => 'TF2', 'price_euro' => $tf2Euro, 'price_dollar' => 0.0, 'price_brl' => 0.0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function counterTrade(): Trade
{
    $games = [
        ['name' => 'Half-Life', 'market_price_euro' => 4.50, 'popularity' => 500, 'region' => null, 'gamivo_id' => null],
        ['name' => 'Portal', 'market_price_euro' => 6.00, 'popularity' => 500, 'region' => 'EU', 'gamivo_id' => null],
    ];

    return app(CreateTradeWithOfferUseCase::class)->execute(
        new TradeWithOfferDTO('76561198000000321', $games, TradeCurrency::Tf2, null, null)
    );
}

function requestCounter(Trade $trade, float $requested): ?Trade
{
    return app(RequestCounterOfferUseCase::class)->execute($trade->uuid, (int) round($requested * 100));
}

/** @return list<float> os valores da oferta vigente, na ordem das linhas */
function currentPrices(Trade $trade): array
{
    return $trade->fresh()->currentOffer->lines->pluck('price')->all();
}

function expectRefused(callable $call, CounterOfferRefusal $reason): void
{
    try {
        $call();
    } catch (CounterOfferRefused $e) {
        expect($e->reason)->toBe($reason);

        return;
    }

    test()->fail("Expected the counter offer to be refused with {$reason->value}.");
}

beforeEach(fn () => seedCounterDeps());

describe('RequestCounterOfferUseCase — rounds', function () {

    it('offers the round-2 target when the supplier asks for more than it', function () {
        $trade = counterTrade();

        requestCounter($trade, 100.00);
        $offer = $trade->fresh()->currentOffer;

        expect($offer->round)->toBe(2)
            ->and($offer->profit_percent)->toBe(60.0)
            ->and($offer->requested_total)->toBe(100.0)
            ->and(currentPrices($trade))->toBe([2.61, 3.54])
            ->and($offer->isFinal())->toBeFalse();
    });

    it('offers exactly what the supplier asked when it fits the round', function () {
        $trade = counterTrade();

        requestCounter($trade, 6.00);
        $prices = currentPrices($trade);

        // Entre a vigente (5,80) e o alvo da rodada (6,15): fecha no pedido
        expect(round(array_sum($prices), 2))->toBe(6.0)
            ->and($prices[0])->toBeGreaterThan(2.46)->toBeLessThanOrEqual(2.61)
            ->and($prices[1])->toBeGreaterThan(3.34)->toBeLessThanOrEqual(3.54);
    });

    it('keeps the games and regions the supplier read, in the same order', function () {
        $trade = counterTrade();

        requestCounter($trade, 100.00);

        expect($trade->fresh()->currentOffer->lines->map->only(['game_name', 'region'])->all())->toBe([
            ['game_name' => 'Half-Life', 'region' => null],
            ['game_name' => 'Portal', 'region' => 'EU'],
        ]);
    });

    it('makes the third offer the final one, at the round-3 target', function () {
        $trade = counterTrade();
        requestCounter($trade, 100.00);

        requestCounter($trade, 100.00);
        $offer = $trade->fresh()->currentOffer;

        expect($offer->round)->toBe(3)
            ->and($offer->profit_percent)->toBe(50.0)
            ->and(currentPrices($trade))->toBe([2.79, 3.78])
            ->and($offer->isFinal())->toBeTrue();
    });

    it('takes the profit target from the first offer, even if the category changed since', function () {
        $trade = counterTrade();
        DB::table('suppliers')->where('id', $trade->supplier_id)->update(['category' => 'vip']);

        requestCounter($trade, 100.00);

        expect($trade->fresh()->currentOffer->profit_percent)->toBe(60.0);
    });
});

describe('RequestCounterOfferUseCase — refusals', function () {

    it('refuses after the final offer', function () {
        $trade = counterTrade();
        requestCounter($trade, 100.00);
        requestCounter($trade, 100.00);

        expectRefused(fn () => requestCounter($trade, 100.00), CounterOfferRefusal::OfferIsFinal);
        expect(TradeOffer::where('trade_id', $trade->id)->count())->toBe(3);
    });

    it('refuses a request that is not above the current offer', function () {
        $trade = counterTrade();

        expectRefused(fn () => requestCounter($trade, 5.80), CounterOfferRefusal::NotAnIncrease);
        expectRefused(fn () => requestCounter($trade, 1.00), CounterOfferRefusal::NotAnIncrease);
        expect(TradeOffer::where('trade_id', $trade->id)->count())->toBe(1);
    });

    it('refuses once the trade was delivered', function () {
        $trade = counterTrade();
        DB::table('trades')->where('id', $trade->id)->update(['delivered_at' => now()]);

        expectRefused(fn () => requestCounter($trade, 100.00), CounterOfferRefusal::TradeNotNegotiating);
    });

    it('refuses a trade without an offer', function () {
        $trade = TradeFactory::withLines(['Half-Life'], ['uuid' => '6ec8ee77-f49b-4582-b11c-43ad7c1c5720']);

        expectRefused(fn () => requestCounter($trade, 100.00), CounterOfferRefusal::NoOffer);
    });

    it('refuses when no game can go up, and writes nothing', function () {
        $trade = counterTrade();
        // A cotação da TF2 subiu: em TF2, o alvo da rodada 2 de cada jogo cai
        // abaixo do que o supplier já leu, e cada jogo mantém o vigente
        DB::table('assets')->update(['price_euro' => 2.00]);
        Cache::flush();

        expectRefused(fn () => requestCounter($trade, 100.00), CounterOfferRefusal::NothingToConcede);
        expect(TradeOffer::where('trade_id', $trade->id)->count())->toBe(1);
    });

    it('answers null for an unknown trade', function () {
        $missing = new Trade(['title' => null]);
        $missing->uuid = '00000000-0000-4000-8000-000000000000';

        expect(requestCounter($missing, 100.00))->toBeNull();
    });
});

describe('RequestCounterOfferUseCase — edge cases', function () {

    it('keeps a game whose trade line was deleted at the value already offered', function () {
        $trade = counterTrade();
        DB::table('trade_lines')->where('trade_id', $trade->id)->where('game_name', 'Half-Life')->delete();

        requestCounter($trade, 100.00);

        // Sem a linha não há como recalcular Half-Life; Portal sobe
        expect(currentPrices($trade))->toBe([2.46, 3.54]);
    });

    it('refuses without the TF2 price in euro, and writes nothing', function () {
        $trade = counterTrade();
        DB::table('assets')->update(['price_euro' => 0.0]);
        Cache::flush();

        expect(fn () => requestCounter($trade, 100.00))->toThrow(CurrencyPriceUnavailable::class)
            ->and(TradeOffer::where('trade_id', $trade->id)->count())->toBe(1);
    });
});
