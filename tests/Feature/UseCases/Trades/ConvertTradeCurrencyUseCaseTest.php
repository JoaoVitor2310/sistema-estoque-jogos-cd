<?php

/*
|--------------------------------------------------------------------------
| ConvertTradeCurrencyUseCase — integration
|--------------------------------------------------------------------------
|
| Trocar a moeda da trade converte a quantidade acertada pelas cotações da TF2,
| e só vale para trade sem oferta. A borda HTTP está em ConvertTradeCurrencyTest.
|
| Cenário: TF2 a € 1,46 e $ 1,70.
|
*/

use App\Domain\Enums\TradeCurrency;
use App\Domain\Pricing\CurrencyPriceUnavailable;
use App\Domain\Trades\TradeCurrencyLocked;
use App\Models\Trade;
use App\Models\TradeOffer;
use App\UseCases\Trades\ConvertTradeCurrencyUseCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function seedConversionQuotes(float $euro = 1.46, float $dollar = 1.70): void
{
    Cache::flush();

    DB::table('assets')->updateOrInsert(
        ['name' => 'TF2'],
        ['price_euro' => $euro, 'price_dollar' => $dollar, 'price_brl' => 0.0, 'created_at' => now(), 'updated_at' => now()],
    );
}

function tradeIn(string $currency, ?string $amount): Trade
{
    return Trade::create(['date' => now()->toDateString(), 'currency' => $currency, 'amount' => $amount]);
}

function convertTrade(Trade $trade, TradeCurrency $target): Trade
{
    return app(ConvertTradeCurrencyUseCase::class)->execute($trade, $target);
}

beforeEach(fn () => seedConversionQuotes());

describe('ConvertTradeCurrencyUseCase — conversion', function () {

    it('converts the amount to the new currency', function (string $from, string $amount, TradeCurrency $target, string $expected) {
        $trade = tradeIn($from, $amount);

        $converted = convertTrade($trade, $target);

        expect($converted->currency)->toBe($target)
            ->and($converted->amount)->toBe($expected)
            ->and($trade->fresh()->currency)->toBe($target)
            ->and($trade->fresh()->amount)->toBe($expected);
    })->with([
        'TF2 to euro' => ['tf2', '10.00', TradeCurrency::Eur, '14.60'],
        'TF2 to dollar' => ['tf2', '10.00', TradeCurrency::Usd, '17.00'],
        'euro to TF2' => ['eur', '14.60', TradeCurrency::Tf2, '10.00'],
        'dollar to euro' => ['usd', '17.00', TradeCurrency::Eur, '14.60'],
    ]);

    it('changes only the currency of a trade with no agreed amount', function () {
        $trade = tradeIn('tf2', null);

        expect(convertTrade($trade, TradeCurrency::Usd))
            ->currency->toBe(TradeCurrency::Usd)
            ->amount->toBeNull();
    });

    it('leaves the trade as it is when the currency is the same', function () {
        $trade = tradeIn('eur', '14.60');

        expect(convertTrade($trade, TradeCurrency::Eur)->amount)->toBe('14.60');
    });

    it('converts from the stored trade, not from a stale copy', function () {
        $trade = tradeIn('tf2', '10.00');
        $stale = Trade::find($trade->id);
        convertTrade($trade, TradeCurrency::Eur);

        // A segunda troca parte de € 14,60, e não dos 10 TF2 que a cópia lembra
        expect(convertTrade($stale, TradeCurrency::Usd)->amount)->toBe('17.00');
    });
});

describe('ConvertTradeCurrencyUseCase — refusals', function () {

    it('refuses a trade that already has an offer, and writes nothing', function () {
        $trade = tradeIn('tf2', '10.00');
        TradeOffer::create([
            'trade_id' => $trade->id, 'round' => 1, 'profit_percent' => 70,
            'currency' => TradeCurrency::Tf2, 'tf2_rate' => 1,
        ]);

        expect(fn () => convertTrade($trade, TradeCurrency::Eur))->toThrow(TradeCurrencyLocked::class)
            ->and($trade->fresh()->currency)->toBe(TradeCurrency::Tf2)
            ->and($trade->fresh()->amount)->toBe('10.00');
    });

    it('refuses without the TF2 price in the new currency', function () {
        seedConversionQuotes(dollar: 0.0);
        $trade = tradeIn('tf2', '10.00');

        expect(fn () => convertTrade($trade, TradeCurrency::Usd))->toThrow(CurrencyPriceUnavailable::class, 'usd')
            ->and($trade->fresh()->currency)->toBe(TradeCurrency::Tf2);
    });

    it('refuses without the TF2 price in the current currency', function () {
        seedConversionQuotes(euro: 0.0);
        $trade = tradeIn('eur', '14.60');

        expect(fn () => convertTrade($trade, TradeCurrency::Tf2))->toThrow(CurrencyPriceUnavailable::class, 'eur')
            ->and($trade->fresh()->amount)->toBe('14.60');
    });

    it('needs only the quotes of the two currencies involved', function () {
        seedConversionQuotes(euro: 0.0);

        expect(convertTrade(tradeIn('tf2', '10.00'), TradeCurrency::Usd)->amount)->toBe('17.00');
    });
});
