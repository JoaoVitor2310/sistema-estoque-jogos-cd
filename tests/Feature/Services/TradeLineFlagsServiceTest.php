<?php

use App\Domain\Trades\OverstockPolicy;
use App\Services\Trades\TradeLineFlagsService;
use Illuminate\Support\Facades\DB;
use Tests\Support\GiveawayFactory;

/** Estoque encalhado de um jogo: keys paradas e velhas o bastante. */
function seedFlagsServiceOverstock(string $gameName, ?string $region = null): void
{
    foreach (range(1, OverstockPolicy::MIN_STOCK) as $i) {
        DB::table('keys')->insert([
            'game_name' => $gameName,
            'region' => $region,
            'key_code' => 'FLAGS-STOCK-'.uniqid(),
            'market_price' => 5.00,
            'individual_cost' => 1.00,
            'min_api' => 1.00,
            'max_api' => 10.00,
            'supplier_url' => 'https://steamcommunity.com/id/test',
            'acquired_at' => now()->subDays(OverstockPolicy::MIN_AGE_DAYS + 10)->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

describe('TradeLineFlagsService::markResearched()', function () {

    it('marks every flag on each researched game', function () {
        seedFlagsServiceOverstock('Curse of the Sea Rats');
        GiveawayFactory::active('Dwarven Realms');

        $marked = app(TradeLineFlagsService::class)->markResearched([
            ['name' => 'Curse of the Sea Rats', 'region' => null],
            ['name' => 'Dwarven Realms', 'region' => null],
            ['name' => 'Portal', 'region' => null],
        ]);

        expect(array_map(fn (array $game) => [$game['is_overstocked'], $game['is_giveaway']], $marked))
            ->toBe([[true, false], [false, true], [false, false]]);
    });
});

describe('TradeLineFlagsService::flagsFor()', function () {

    it('answers every flag of one line as trade line attributes', function () {
        seedFlagsServiceOverstock('Dwarven Realms', 'ROW');
        GiveawayFactory::active('Dwarven Realms');

        expect(app(TradeLineFlagsService::class)->flagsFor('Dwarven Realms', 'ROW'))
            ->toBe(['is_overstocked' => true, 'is_giveaway' => true])
            ->and(app(TradeLineFlagsService::class)->flagsFor('Dwarven Realms', 'EU'))
            ->toBe(['is_overstocked' => false, 'is_giveaway' => true]);
    });

    it('flags nothing on a line without a game', function () {
        expect(app(TradeLineFlagsService::class)->flagsFor(null, null))->toBe(['is_overstocked' => false, 'is_giveaway' => false]);
    });
});
