<?php

use App\Domain\Trades\GameStock;
use Carbon\Carbon;

function gameStock(array $overrides = []): GameStock
{
    return new GameStock(
        $overrides['normalizedName'] ?? 'half life',
        $overrides['region'] ?? null,
        $overrides['displayName'] ?? 'Half-Life',
        $overrides['stock'] ?? 3,
        array_key_exists('oldestAcquiredAt', $overrides) ? $overrides['oldestAcquiredAt'] : gameStockNow()->copy()->subDays(60),
        $overrides['soldInWindow'] ?? 0,
    );
}

function gameStockNow(): Carbon
{
    return Carbon::parse('2026-01-01');
}

describe('GameStock::identityOf()', function () {

    it('joins spellings of the same game in the same region', function () {
        // "Alien Shooter 2: Reloaded" e "Alien Shooter 2 Reloaded" são o mesmo
        // produto: contar cada grafia como um jogo subestimaria o estoque.
        expect(GameStock::identityOf('Alien Shooter 2: Reloaded', 'EU'))
            ->toBe(GameStock::identityOf('Alien Shooter 2 Reloaded', 'EU'));
    });

    it('separates the same game in different regions', function () {
        // Estoque parado em ROW não vira venda em EU.
        expect(GameStock::identityOf('Portal', 'EU'))
            ->not->toBe(GameStock::identityOf('Portal', 'ROW'));
    });

    it('treats a null region as the global region, apart from any written one', function () {
        expect(GameStock::identityOf('Portal', null))
            ->not->toBe(GameStock::identityOf('Portal', 'EU'))
            ->and(GameStock::identityOf('Portal', null))->toBe(GameStock::identityOf('Portal', '  '));
    });

    it('ignores case and surrounding spaces in the region', function () {
        expect(GameStock::identityOf('Portal', ' latam '))->toBe(GameStock::identityOf('Portal', 'LATAM'));
    });

    it('matches the identity of a stock built from the same pair', function () {
        // Os dois lados — estoque agregado e linha da trade — precisam produzir
        // a mesma chave; divergir não falha, só deixa de sinalizar.
        $game = gameStock(['normalizedName' => 'portal', 'region' => 'EU']);

        expect($game->identity())->toBe(GameStock::identityOf('Portal', 'eu'));
    });
});

describe('GameStock::daysInStock()', function () {

    it('counts the days since the oldest stopped key was acquired', function () {
        $game = gameStock(['oldestAcquiredAt' => gameStockNow()->copy()->subDays(63)]);

        expect($game->daysInStock(gameStockNow()))->toBe(63);
    });

    it('returns null for stock without an acquisition date', function () {
        expect(gameStock(['oldestAcquiredAt' => null])->daysInStock(gameStockNow()))->toBeNull();
    });
});

describe('GameStock::combine()', function () {

    it('sums stock and sales of two spellings of the same game', function () {
        $first = gameStock(['stock' => 2, 'soldInWindow' => 1]);
        $second = gameStock(['stock' => 3, 'soldInWindow' => 4]);

        $combined = $first->combine($second);

        expect($combined->stock)->toBe(5)
            ->and($combined->soldInWindow)->toBe(5);
    });

    it('keeps the oldest acquisition of the two', function () {
        $older = gameStockNow()->copy()->subDays(300);

        $combined = gameStock(['oldestAcquiredAt' => gameStockNow()])
            ->combine(gameStock(['oldestAcquiredAt' => $older]));

        expect($combined->oldestAcquiredAt->toDateString())->toBe($older->toDateString());
    });

    it('falls back to the spelling that has an acquisition date', function () {
        $combined = gameStock(['oldestAcquiredAt' => null])
            ->combine(gameStock(['oldestAcquiredAt' => gameStockNow()]));

        expect($combined->oldestAcquiredAt?->toDateString())->toBe(gameStockNow()->toDateString());
    });

    it('displays the spelling with more stopped keys', function () {
        $combined = gameStock(['displayName' => 'Sticky Business', 'stock' => 1])
            ->combine(gameStock(['displayName' => 'STICKY BUSINESS', 'stock' => 8]));

        expect($combined->displayName)->toBe('STICKY BUSINESS');
    });
});
