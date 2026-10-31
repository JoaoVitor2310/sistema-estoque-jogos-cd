<?php

/*
|--------------------------------------------------------------------------
| CreateTradeWithOfferUseCase — integration
|--------------------------------------------------------------------------
|
| A trade da lista precificada nasce já com a primeira oferta ao supplier, e
| quando não há o que ofertar nada fica gravado. Regras em
| docs/PRODUCT.md ("Oferta ao supplier").
|
*/

use App\Domain\Enums\SupplierCategory;
use App\Domain\Enums\TradeCurrency;
use App\Domain\Pricing\CurrencyPriceUnavailable;
use App\Models\Trade;
use App\Models\TradeOffer;
use App\UseCases\Trades\CreateTradeWithOfferUseCase;
use App\UseCases\Trades\DTO\TradeWithOfferDTO;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\GiveawayFactory;

const WITH_OFFER_STEAM_ID = '76561198000000042';

function seedWithOfferDeps(float $tf2Euro = 0.95, float $tf2Dollar = 0.0): void
{
    Cache::flush();

    DB::table('fees')->insertOrIgnore([
        ['name' => 'gamivo_percent_low', 'preco' => 0.06, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_low', 'preco' => 0.25, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_percent_high', 'preco' => 0.08, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_high', 'preco' => 0.40, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('assets')->insert([
        'name' => 'TF2', 'price_euro' => $tf2Euro, 'price_dollar' => $tf2Dollar, 'price_brl' => 0.00,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function withOfferGame(string $name, float $price, ?string $region = null): array
{
    return ['name' => $name, 'market_price_euro' => $price, 'popularity' => 500, 'region' => $region, 'gamivo_id' => null];
}

function createTradeWithOffer(array $games, TradeCurrency $currency = TradeCurrency::Tf2, ?string $title = null, ?string $listCode = null): ?Trade
{
    return app(CreateTradeWithOfferUseCase::class)->execute(
        new TradeWithOfferDTO(WITH_OFFER_STEAM_ID, $games, $currency, $title, $listCode)
    );
}

function withOfferSupplier(?SupplierCategory $category, ?string $name = null): void
{
    DB::table('suppliers')->insert([
        'steam_id' => WITH_OFFER_STEAM_ID, 'name' => $name, 'category' => $category?->value,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

describe('CreateTradeWithOfferUseCase', function () {

    beforeEach(fn () => seedWithOfferDeps());

    it('creates the trade with its lines, a delivery credential and the first offer', function () {
        $trade = createTradeWithOffer([withOfferGame('Half-Life', 4.50, 'EU'), withOfferGame('Portal', 6.00)]);

        $offer = $trade->currentOffer;

        expect($trade->uuid)->not->toBeNull()
            ->and($trade->readableDeliveryToken())->not->toBeNull()
            ->and($trade->lines()->pluck('game_name')->all())->toBe(['Half-Life', 'Portal'])
            ->and($offer->round)->toBe(1)
            ->and($offer->lines->pluck('game_name')->all())->toBe(['Half-Life', 'Portal'])
            ->and(TradeOffer::where('trade_id', $trade->id)->count())->toBe(1);
    });

    it('creates a supplier it does not know yet, as normal (70%)', function () {
        $trade = createTradeWithOffer([withOfferGame('Half-Life', 4.50)]);

        expect($trade->supplier->steam_id)->toBe(WITH_OFFER_STEAM_ID)
            ->and($trade->supplier->category)->toBeNull()
            ->and($trade->currentOffer->profit_percent)->toBe(70.0);
    });

    it('offers with the profit of the supplier category', function () {
        withOfferSupplier(SupplierCategory::Vip);

        expect(createTradeWithOffer([withOfferGame('Half-Life', 4.50)])->currentOffer->profit_percent)->toBe(80.0);
    });

    it('keeps a game in giveaway as a flagged line, out of the offer', function () {
        GiveawayFactory::active('Portal');

        $trade = createTradeWithOffer([withOfferGame('Half-Life', 4.50), withOfferGame('Portal', 6.00)]);

        expect($trade->lines()->where('game_name', 'Portal')->value('is_giveaway'))->toBeTruthy()
            ->and($trade->currentOffer->lines->pluck('game_name')->all())->toBe(['Half-Life']);
    });

    it('names the trade after the list, or after the supplier when the list has no title', function () {
        withOfferSupplier(null, 'Fulano');

        expect(createTradeWithOffer([withOfferGame('Half-Life', 4.50)], listCode: 'AbC12')->title)->toBe('Fulano');

        $listed = createTradeWithOffer([withOfferGame('Portal', 6.00)], title: 'Lista de outubro', listCode: 'XyZ99');

        expect($listed->title)->toBe('Lista de outubro')
            ->and($listed->list_code)->toBe('XyZ99');
    });

    it('creates nothing for a blocked supplier', function () {
        withOfferSupplier(SupplierCategory::Blocked);

        expect(createTradeWithOffer([withOfferGame('Half-Life', 4.50)]))->toBeNull()
            ->and(Trade::count())->toBe(0)
            ->and(DB::table('trade_lines')->count())->toBe(0)
            ->and(TradeOffer::count())->toBe(0);
    });

    it('creates nothing, not even the supplier, when no game is offerable', function () {
        GiveawayFactory::active('Portal');

        // Um jogo em giveaway e um que não paga as taxas
        expect(createTradeWithOffer([withOfferGame('Portal', 6.00), withOfferGame('Centavos', 0.05)]))->toBeNull()
            ->and(Trade::count())->toBe(0)
            ->and(DB::table('suppliers')->where('steam_id', WITH_OFFER_STEAM_ID)->exists())->toBeFalse();
    });
});

describe('CreateTradeWithOfferUseCase — currency', function () {

    it('creates the trade and the offer in the currency the supplier chose', function () {
        seedWithOfferDeps(tf2Euro: 0.95);

        $trade = createTradeWithOffer([withOfferGame('Half-Life', 4.50)], TradeCurrency::Eur);

        expect($trade->currency)->toBe(TradeCurrency::Eur)
            ->and($trade->currentOffer->currency)->toBe(TradeCurrency::Eur)
            ->and($trade->currentOffer->tf2_rate)->toBe(0.95);
    });

    it('falls back to TF2 when there is no TF2 price in the chosen currency', function () {
        seedWithOfferDeps(tf2Dollar: 0.0);

        $trade = createTradeWithOffer([withOfferGame('Half-Life', 4.50)], TradeCurrency::Usd);

        expect($trade->currency)->toBe(TradeCurrency::Tf2)
            ->and($trade->fresh()->currency)->toBe(TradeCurrency::Tf2)
            ->and($trade->currentOffer->currency)->toBe(TradeCurrency::Tf2);
    });

    it('refuses without the TF2 price in euro, which every offer starts from, and writes nothing', function () {
        seedWithOfferDeps(tf2Euro: 0.0);

        expect(fn () => createTradeWithOffer([withOfferGame('Half-Life', 4.50)]))
            ->toThrow(CurrencyPriceUnavailable::class)
            ->and(Trade::count())->toBe(0);
    });
});
