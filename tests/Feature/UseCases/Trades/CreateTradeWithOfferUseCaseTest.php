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
use App\Domain\Trades\RequestReusedForAnotherSupplier;
use App\Models\Supplier;
use App\Models\Trade;
use App\Models\TradeOffer;
use App\UseCases\Trades\CreateTradeWithOfferUseCase;
use App\UseCases\Trades\DTO\TradeWithOfferDTO;
use App\UseCases\Trades\RequestCounterOfferUseCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

function createTradeWithOffer(array $games, TradeCurrency $currency = TradeCurrency::Tf2, ?string $title = null, ?string $listCode = null, ?string $requestUuid = null): ?Trade
{
    return app(CreateTradeWithOfferUseCase::class)->execute(
        new TradeWithOfferDTO(WITH_OFFER_STEAM_ID, $games, $currency, $title, $listCode, $requestUuid)
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

describe('CreateTradeWithOfferUseCase — idempotency by request_uuid', function () {

    beforeEach(fn () => seedWithOfferDeps());

    it('returns the trade the same request already created, and records nothing new', function () {
        // O price_researcher repete a chamada num timeout: sem a chave, o
        // supplier veria duas trades com duas ofertas.
        $requestUuid = (string) Str::uuid();

        $first = createTradeWithOffer([withOfferGame('Half-Life', 4.50)], requestUuid: $requestUuid);
        $again = createTradeWithOffer([withOfferGame('Half-Life', 4.50)], requestUuid: $requestUuid);

        expect($again->id)->toBe($first->id)
            ->and($again->uuid)->toBe($first->uuid)
            ->and($again->currentOffer)->not->toBeNull()
            ->and(Trade::count())->toBe(1)
            ->and(TradeOffer::count())->toBe(1);
    });

    it('stores the request on the trade it creates', function () {
        $requestUuid = (string) Str::uuid();

        $trade = createTradeWithOffer([withOfferGame('Half-Life', 4.50)], requestUuid: $requestUuid);

        expect($trade->fresh()->request_uuid)->toBe($requestUuid);
    });

    it('creates another trade for another request', function () {
        createTradeWithOffer([withOfferGame('Half-Life', 4.50)], requestUuid: (string) Str::uuid());
        createTradeWithOffer([withOfferGame('Half-Life', 4.50)], requestUuid: (string) Str::uuid());

        expect(Trade::count())->toBe(2);
    });

    it('always creates when the request has no request_uuid, as before', function () {
        createTradeWithOffer([withOfferGame('Half-Life', 4.50)]);
        createTradeWithOffer([withOfferGame('Half-Life', 4.50)]);

        expect(Trade::count())->toBe(2)
            ->and(Trade::whereNotNull('request_uuid')->count())->toBe(0);
    });

    it('processes again a request that recorded nothing the first time', function () {
        // Sem o que ofertar nada é gravado, então a repetição não tem o que
        // devolver: o pedido é avaliado de novo, agora com o jogo que vale.
        $requestUuid = (string) Str::uuid();

        $nothing = createTradeWithOffer([withOfferGame('Free Game', 0.05)], requestUuid: $requestUuid);
        $created = createTradeWithOffer([withOfferGame('Half-Life', 4.50)], requestUuid: $requestUuid);

        expect($nothing)->toBeNull()
            ->and($created)->not->toBeNull()
            ->and(Trade::count())->toBe(1);
    });

    it('does not hold the request of a deleted trade', function () {
        // Índice parcial, como o de suppliers.steam_id: a trade soft-deletada
        // não bloqueia o pedido.
        $requestUuid = (string) Str::uuid();

        createTradeWithOffer([withOfferGame('Half-Life', 4.50)], requestUuid: $requestUuid)->delete();
        $again = createTradeWithOffer([withOfferGame('Half-Life', 4.50)], requestUuid: $requestUuid);

        expect($again)->not->toBeNull()
            ->and(Trade::count())->toBe(1)
            ->and(Trade::withTrashed()->count())->toBe(2);
    });

    it('answers with the trade of the concurrent call when two calls of the same request race', function () {
        // A corrida real: as duas chamadas buscam o pedido antes de qualquer
        // uma gravar. Aqui a "outra" grava logo depois da busca desta, e o
        // índice único barra esta na hora de gravar o pedido.
        $requestUuid = (string) Str::uuid();
        $competing = null;

        DB::listen(function ($query) use (&$competing, $requestUuid) {
            if ($competing === null && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'request_uuid')) {
                // A outra chamada é do mesmo pedido, então do mesmo supplier.
                $supplier = Supplier::create(['steam_id' => WITH_OFFER_STEAM_ID, 'url' => 'https://steamcommunity.com/profiles/'.WITH_OFFER_STEAM_ID]);
                $competing = Trade::create(['supplier_id' => $supplier->id, 'date' => now()->format('Y-m-d')]);
                $competing->forceFill(['uuid' => (string) Str::uuid(), 'request_uuid' => $requestUuid])->save();
            }
        });

        $trade = createTradeWithOffer([withOfferGame('Half-Life', 4.50)], requestUuid: $requestUuid);

        expect($trade->id)->toBe($competing->id)
            ->and(Trade::count())->toBe(1)
            ->and(TradeOffer::count())->toBe(0);
    });

    it('refuses a request_uuid that already created the trade of another supplier', function () {
        // Devolver a trade alheia entregaria a oferta e o código de entrega de
        // outra pessoa a quem lê o uuid.
        $requestUuid = (string) Str::uuid();
        createTradeWithOffer([withOfferGame('Half-Life', 4.50)], requestUuid: $requestUuid);

        $otherSupplier = new TradeWithOfferDTO('76561198000000099', [withOfferGame('Half-Life', 4.50)], TradeCurrency::Tf2, null, null, $requestUuid);

        expect(fn () => app(CreateTradeWithOfferUseCase::class)->execute($otherSupplier))
            ->toThrow(RequestReusedForAnotherSupplier::class);

        expect(Trade::count())->toBe(1);
    });

    it('returns the same trade on a retry that comes after a counter offer', function () {
        // A repetição pode chegar depois de a negociação andar: a trade é a
        // mesma, e nada da primeira oferta é regravado.
        $requestUuid = (string) Str::uuid();
        $first = createTradeWithOffer([withOfferGame('Half-Life', 4.50)], requestUuid: $requestUuid);

        $requestedCents = (int) round(($first->currentOffer->totalPrice() + 0.05) * 100);
        app(RequestCounterOfferUseCase::class)->execute($first->uuid, $requestedCents);

        $again = createTradeWithOffer([withOfferGame('Half-Life', 4.50)], requestUuid: $requestUuid);

        expect($again->id)->toBe($first->id)
            ->and($again->currentOffer->round)->toBe(2)
            ->and(TradeOffer::count())->toBe(2);
    });
});
