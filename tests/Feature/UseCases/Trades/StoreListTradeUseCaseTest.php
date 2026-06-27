<?php

use App\UseCases\Trades\StoreListTradeUseCase;
use Illuminate\Support\Facades\DB;

function listTradeGames(array $overrides = []): array
{
    return array_merge([
        ['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => 'EU'],
    ], $overrides);
}

describe('StoreListTradeUseCase', function () {

    it('creates a trade with correctly converted games', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => 'EU']],
        ]);

        $game = $trade->games[0];

        expect($game['name'])->toBe('Half-Life')
            ->and($game['marketPriceRaw'])->toBe('4.50')
            ->and($game['popularity'])->toBe('500')
            ->and($game['regionLock'])->toBe('EU')
            ->and($game['bundle'])->toBeNull()
            ->and($game['expiry'])->toBeNull()
            ->and($game['keyCode'])->toBeNull();
    });

    it('sets date to today', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => listTradeGames(),
        ]);

        expect($trade->date->toDateString())->toBe(now()->toDateString());
    });

    it('creates and links supplier when supplier_steam_id is provided', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'supplier_steam_id' => '76561198000000001',
            'games' => listTradeGames(),
        ]);

        $supplier = DB::table('suppliers')->where('steam_id', '76561198000000001')->first();
        expect($supplier)->not->toBeNull()
            ->and($supplier->url)->toBe('https://steamcommunity.com/profiles/76561198000000001')
            ->and($trade->supplier_id)->toBe($supplier->id);
    });

    it('does not duplicate supplier on repeated calls with same steam_id', function () {
        $execute = fn () => app(StoreListTradeUseCase::class)->execute([
            'supplier_steam_id' => '76561198000000001',
            'games' => listTradeGames(),
        ]);

        $execute();
        $execute();

        expect(DB::table('suppliers')->where('steam_id', '76561198000000001')->count())->toBe(1);
    });

    it('creates trade without supplier_id when supplier_steam_id is absent', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => listTradeGames(),
        ]);

        expect($trade->supplier_id)->toBeNull();
    });

    it('stores list_code when provided', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'list_code' => 'G0eXM',
            'games' => listTradeGames(),
        ]);

        expect($trade->list_code)->toBe('G0eXM');
    });

    it('stores null list_code when not provided', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => listTradeGames(),
        ]);

        expect($trade->list_code)->toBeNull();
    });

    it('formats price_euro with period and 2 decimal places', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [['name' => 'Portal', 'price_euro' => 10.0, 'popularity' => 100, 'region' => null]],
        ]);

        expect($trade->games[0]['marketPriceRaw'])->toBe('10.00');
    });

    it('handles null region as null regionLock', function () {
        $trade = app(StoreListTradeUseCase::class)->execute([
            'games' => [['name' => 'Portal', 'price_euro' => 3.00, 'popularity' => 100, 'region' => null]],
        ]);

        expect($trade->games[0]['regionLock'])->toBeNull();
    });
});
