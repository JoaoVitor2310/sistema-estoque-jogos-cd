<?php

/*
|--------------------------------------------------------------------------
| TradeOfferService — integration
|--------------------------------------------------------------------------
|
| A primeira oferta de uma trade: lucro pela categoria do supplier, valor por
| jogo limitado à oferta máxima, encalhado e giveaway fora, e o registro
| gravado como fato imutável (docs/adr/0016).
|
*/

use App\Domain\Enums\PurchaseChannel;
use App\Domain\Enums\SupplierCategory;
use App\Domain\Enums\TradeCurrency;
use App\Domain\Pricing\CurrencyPriceUnavailable;
use App\Domain\Pricing\IncomeCalculator;
use App\Domain\Pricing\SupplierOffer;
use App\Domain\Pricing\ValueObjects\MarketplaceFee;
use App\Models\Trade;
use App\Models\TradeOffer;
use App\Services\Trades\TradeOfferService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\TradeFactory;

const OFFER_TEST_TF2_EURO = 0.95;

function seedOfferDeps(float $tf2Euro = OFFER_TEST_TF2_EURO, float $tf2Dollar = 0.0): void
{
    Cache::flush();

    DB::table('fees')->insertOrIgnore([
        ['name' => 'gamivo_percent_low', 'preco' => 0.06, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_low', 'preco' => 0.25, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_percent_high', 'preco' => 0.08, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_high', 'preco' => 0.40, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('assets')->insert([
        'name' => 'TF2',
        'price_euro' => $tf2Euro,
        'price_dollar' => $tf2Dollar,
        'price_brl' => 0.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** Trade de um supplier da categoria dada, com as linhas pedidas. */
function offerTrade(?SupplierCategory $category, array $lines, TradeCurrency $currency = TradeCurrency::Tf2): Trade
{
    $supplierId = DB::table('suppliers')->insertGetId([
        'steam_id' => '7656119800000'.random_int(1000, 9999),
        'category' => $category?->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return TradeFactory::withLines($lines, ['supplier_id' => $supplierId, 'currency' => $currency->value]);
}

function offerLine(string $name, float $marketPrice, array $attrs = []): array
{
    return ['game_name' => $name, 'market_price' => $marketPrice] + $attrs;
}

/** Oferta exata esperada para um preço de mercado, pela mesma peça de Domain. */
function expectedTf2Offer(float $marketPrice, int $profitPercent): float
{
    $netIncome = IncomeCalculator::forGamivo($marketPrice, new MarketplaceFee(0.06, 0.25, 0.08, 0.40));

    return SupplierOffer::line($netIncome, $profitPercent, OFFER_TEST_TF2_EURO, PurchaseChannel::SupplierTrade)->tf2Offer;
}

function recordInitialOffer(Trade $trade): ?TradeOffer
{
    return app(TradeOfferService::class)->recordInitial($trade);
}

describe('TradeOfferService::recordInitial()', function () {

    beforeEach(fn () => seedOfferDeps());

    it('offers a normal supplier 70% profit on each game', function () {
        $trade = offerTrade(null, [offerLine('Half-Life', 4.50, ['region' => 'EU'])]);

        $offer = recordInitialOffer($trade);
        $line = $offer->lines->sole();

        // 4,50 € → income 3,98 € → 3,98 / 1,70 / 0,95 = 2,4644 TF2 → 2,46
        expect($offer->round)->toBe(1)
            ->and($offer->profit_percent)->toBe(70.0)
            ->and($line->price)->toBe(2.46)
            ->and($offer->isFinal())->toBeFalse()
            ->and($offer->requested_total)->toBeNull();
    });

    it('takes the initial profit from the supplier category', function (?SupplierCategory $category, int $percent) {
        $offer = recordInitialOffer(offerTrade($category, [offerLine('Half-Life', 4.50)]));

        expect($offer->profit_percent)->toBe((float) $percent)
            ->and($offer->lines->sole()->price)->toBe(round(expectedTf2Offer(4.50, $percent), 2));
    })->with([
        'normal' => [null, 70],
        'vip' => [SupplierCategory::Vip, 80],
        'retail' => [SupplierCategory::Retail, 60],
    ]);

    it('leaves overstocked and giveaway games out of the offer', function () {
        $trade = offerTrade(null, [
            offerLine('Half-Life', 4.50),
            offerLine('Portal', 6.00, ['is_overstocked' => true]),
            offerLine('Dwarven Realms', 9.99, ['is_giveaway' => true]),
        ]);

        expect(recordInitialOffer($trade)->lines->pluck('game_name')->all())->toBe(['Half-Life']);
    });

    it('leaves out lines without a game or a price, and games that do not cover the fees', function () {
        $trade = offerTrade(null, [
            offerLine('Half-Life', 4.50),
            ['game_name' => 'Sem preço'],
            ['market_price' => 4.50],
            offerLine('Centavos', 0.05),
        ]);

        expect(recordInitialOffer($trade)->lines->pluck('game_name')->all())->toBe(['Half-Life']);
    });

    it('leaves out a game whose maximum offer cannot pay the minimum price', function () {
        // 0,29 € → income 0,0226 € → oferta máxima 0,0226 / 1,55 = 0,0146 €. Com a TF2 a
        // 2,00 €, são 0,0073 TF2: pagar o piso de 0,01 TF2 passaria do min_api
        DB::table('assets')->update(['price_euro' => 2.00]);
        Cache::flush();

        $trade = offerTrade(null, [offerLine('Half-Life', 4.50), offerLine('Centavos', 0.29)]);

        expect(recordInitialOffer($trade)->lines->pluck('game_name')->all())->toBe(['Half-Life']);
    });

    it('totals the lines already rounded, as the supplier reads them', function () {
        $trade = offerTrade(null, [offerLine('Half-Life', 4.50), offerLine('Portal', 6.00), offerLine('Quake', 12.00)]);

        $offer = recordInitialOffer($trade);

        expect($offer->totalPrice())->toBe(round($offer->lines->sum('price'), 2))
            ->and($offer->totalPrice())->toBeGreaterThan(0.0);
    });

    it('keeps a copy of the game and region the supplier read', function () {
        $trade = offerTrade(null, [offerLine('Half-Life', 4.50, ['region' => 'EU'])]);
        $offer = recordInitialOffer($trade);

        // O supplier edita a região na página da entrega depois da oferta
        DB::table('trade_lines')->where('trade_id', $trade->id)->update(['region' => 'ROW', 'game_name' => 'Half-Life 2']);

        $line = $offer->lines()->sole();
        expect($line->game_name)->toBe('Half-Life')
            ->and($line->region)->toBe('EU')
            ->and($line->trade_line_id)->toBe($trade->lines->sole()->id);
    });

    it('converts the offer into the trade currency with the TF2 price of the moment', function () {
        $trade = offerTrade(null, [offerLine('Half-Life', 4.50)], TradeCurrency::Eur);

        $offer = recordInitialOffer($trade);

        // 2,4644 TF2 × 0,95 €/TF2 = 2,3412 → 2,34
        expect($offer->currency)->toBe(TradeCurrency::Eur)
            ->and($offer->tf2_rate)->toBe(OFFER_TEST_TF2_EURO)
            ->and($offer->lines->sole()->price)->toBe(2.34);
    });

    it('records a TF2 offer with the TF2 unit as its price', function () {
        $offer = recordInitialOffer(offerTrade(null, [offerLine('Half-Life', 4.50)]));

        expect($offer->currency)->toBe(TradeCurrency::Tf2)
            ->and($offer->tf2_rate)->toBe(TradeCurrency::TF2_UNIT_PRICE);
    });

    it('makes no offer to a blocked supplier, and writes nothing', function () {
        expect(recordInitialOffer(offerTrade(SupplierCategory::Blocked, [offerLine('Half-Life', 4.50)])))->toBeNull()
            ->and(TradeOffer::count())->toBe(0);
    });

    it('makes no offer when no game is offerable, and writes nothing', function () {
        $trade = offerTrade(null, [offerLine('Portal', 6.00, ['is_overstocked' => true]), offerLine('Centavos', 0.05)]);

        expect(recordInitialOffer($trade))->toBeNull()
            ->and(TradeOffer::count())->toBe(0);
    });

    it('refuses to record the initial offer twice', function () {
        // O callback do price_researcher pode chegar repetido
        $trade = offerTrade(null, [offerLine('Half-Life', 4.50)]);
        recordInitialOffer($trade);

        expect(fn () => recordInitialOffer($trade->fresh()))->toThrow(LogicException::class)
            ->and(TradeOffer::count())->toBe(1);
    });

    it('keeps one offer per round even past the service', function () {
        $offer = recordInitialOffer(offerTrade(null, [offerLine('Half-Life', 4.50)]));

        expect(fn () => DB::table('trade_offers')->insert([
            'trade_id' => $offer->trade_id, 'round' => 1, 'profit_percent' => 70, 'currency' => 'tf2',
            'tf2_rate' => 1, 'created_at' => now(),
        ]))->toThrow(Illuminate\Database\QueryException::class);
    });

    it('refuses to make an offer to a trade without a supplier', function () {
        $trade = TradeFactory::withLines([offerLine('Half-Life', 4.50)]);

        expect(fn () => recordInitialOffer($trade))->toThrow(LogicException::class);
    });
});

describe('TradeOfferService::recordInitial() — without a TF2 price', function () {

    it('refuses a cash offer without the TF2 price in that currency, and writes nothing', function () {
        seedOfferDeps(tf2Dollar: 0.0);

        expect(fn () => recordInitialOffer(offerTrade(null, [offerLine('Half-Life', 4.50)], TradeCurrency::Usd)))
            ->toThrow(CurrencyPriceUnavailable::class)
            ->and(TradeOffer::count())->toBe(0);
    });

    it('refuses any offer without the TF2 price in euro, which every offer starts from', function () {
        seedOfferDeps(tf2Euro: 0.0);

        // A trade é em TF2, mas o que falta é o euro — e é ele que o erro aponta
        expect(fn () => recordInitialOffer(offerTrade(null, [offerLine('Half-Life', 4.50)])))
            ->toThrow(CurrencyPriceUnavailable::class, 'No TF2 price available in eur.');
    });
});

describe('Trade offers are records', function () {

    beforeEach(fn () => seedOfferDeps());

    it('refuses to change an offer already made', function () {
        $offer = recordInitialOffer(offerTrade(null, [offerLine('Half-Life', 4.50)]));

        expect(fn () => $offer->update(['profit_percent' => 10]))->toThrow(LogicException::class)
            ->and(fn () => $offer->lines->sole()->update(['price' => 99]))->toThrow(LogicException::class)
            ->and($offer->fresh()->profit_percent)->toBe(70.0)
            ->and($offer->fresh()->lines->sole()->price)->not->toBe(99.0);
    });

    it('calls the offer of the last round final', function () {
        $trade = offerTrade(null, [offerLine('Half-Life', 4.50)]);
        recordInitialOffer($trade);

        $last = TradeOffer::create([
            'trade_id' => $trade->id, 'round' => SupplierOffer::MAX_ROUNDS, 'profit_percent' => 50,
            'currency' => TradeCurrency::Tf2, 'tf2_rate' => 1, 'requested_total' => 5,
        ]);

        expect($last->isFinal())->toBeTrue();
    });

    it('treats the offer of the highest round as the current one', function () {
        $trade = offerTrade(null, [offerLine('Half-Life', 4.50)]);
        $first = recordInitialOffer($trade);

        // A contraproposta ainda não tem caminho próprio: a rodada 2 entra direto
        $second = TradeOffer::create([
            'trade_id' => $trade->id, 'round' => 2, 'profit_percent' => 60, 'currency' => TradeCurrency::Tf2,
            'tf2_rate' => 1, 'requested_total' => 3,
        ]);

        expect($trade->fresh()->currentOffer->id)->toBe($second->id)
            ->and($trade->fresh()->offers->pluck('round')->all())->toBe([1, 2])
            ->and($first->isFinal())->toBeFalse()
            ->and($second->requested_total)->toBe(3.0);
    });
});
