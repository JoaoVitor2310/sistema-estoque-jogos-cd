<?php

/*
|--------------------------------------------------------------------------
| OverstockService — estoque encalhado
|--------------------------------------------------------------------------
|
| A regra em si é do Domain (tests/Unit/Domain/Trades/OverstockPolicyTest.php).
| Aqui se testa o encontro dela com o banco: a marca que as linhas recebem e a
| lista de jogos encalhados.
|
*/

use App\Domain\Trades\OverstockPolicy;
use App\Services\Trades\OverstockService;
use Illuminate\Support\Facades\DB;

function seedStockedKey(string $gameName, array $overrides = []): void
{
    DB::table('keys')->insert(array_merge([
        'game_name' => $gameName,
        'key_code' => 'STOCK-'.uniqid(),
        'market_price' => 5.00,
        'individual_cost' => 1.00,
        'min_api' => 1.00,
        'max_api' => 10.00,
        'supplier_url' => 'https://steamcommunity.com/id/test',
        'region' => null,
        'acquired_at' => now()->subDays(OverstockPolicy::MIN_AGE_DAYS + 10)->toDateString(),
        'sold_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/** Um jogo que bate as três condições numa região: estoque, idade e nenhuma venda. */
function seedOverstockedGame(string $gameName, int $stock = OverstockPolicy::MIN_STOCK, ?string $region = null): void
{
    for ($i = 0; $i < $stock; $i++) {
        seedStockedKey($gameName, ['region' => $region]);
    }
}

describe('OverstockService::markResearched()', function () {

    it('flags the overstocked game and leaves the others', function () {
        seedOverstockedGame('Curse of the Sea Rats');

        $lines = app(OverstockService::class)->markResearched([
            ['name' => 'Curse of the Sea Rats', 'region' => null],
            ['name' => 'Half-Life', 'region' => null],
        ]);

        expect(array_column($lines, 'is_overstocked'))->toBe([true, false]);
    });

    it('keeps every researched game, flagged or not', function () {
        // Quem decide o que fazer com a marca é o chamador: a oferta exclui, a
        // linha da trade mantém.
        seedOverstockedGame('Curse of the Sea Rats');

        $lines = app(OverstockService::class)->markResearched([['name' => 'Curse of the Sea Rats', 'region' => null]]);

        expect($lines)->toHaveCount(1)
            ->and($lines[0]['name'])->toBe('Curse of the Sea Rats');
    });

    it('flags only the region where the game is overstocked', function () {
        seedOverstockedGame('Portal', region: 'ROW');

        $lines = app(OverstockService::class)->markResearched([
            ['name' => 'Portal', 'region' => 'ROW'],
            ['name' => 'Portal', 'region' => 'EU'],
            ['name' => 'Portal', 'region' => null],
        ]);

        expect(array_column($lines, 'is_overstocked'))->toBe([true, false, false]);
    });

    it('does not let a region that sells hide the one that is overstocked', function () {
        // Somados, EU vendendo bem puxaria o escoamento para dentro do limite
        // e ROW deixaria de ser sinalizado.
        seedOverstockedGame('Portal', 6, 'ROW');
        seedStockedKey('Portal', ['region' => 'EU']);
        for ($i = 0; $i < 10; $i++) {
            seedStockedKey('Portal', ['region' => 'EU', 'sold_at' => now()->subDays(5)->toDateString()]);
        }

        $lines = app(OverstockService::class)->markResearched([['name' => 'Portal', 'region' => 'ROW']]);

        expect($lines[0]['is_overstocked'])->toBeTrue();
    });

    it('does not flag a game that sells fast enough for its stock', function () {
        seedOverstockedGame('NORDHOLD', 5);
        // 7 vendas na janela: escoa em ~64 dias, dentro do limite.
        for ($i = 0; $i < 7; $i++) {
            seedStockedKey('NORDHOLD', ['sold_at' => now()->subDays(5)->toDateString()]);
        }

        $lines = app(OverstockService::class)->markResearched([['name' => 'NORDHOLD', 'region' => null]]);

        expect($lines[0]['is_overstocked'])->toBeFalse();
    });

    it('does not query the stock when no game is named', function () {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $lines = app(OverstockService::class)->markResearched([['name' => null, 'region' => null]]);

        expect($lines[0]['is_overstocked'])->toBeFalse()
            ->and($queries)->toBe(0);
    });
});

describe('OverstockService::isOverstocked()', function () {

    it('answers for a single game and region', function () {
        seedOverstockedGame('Depth', region: 'EU');

        $service = app(OverstockService::class);

        expect($service->isOverstocked('Depth', 'eu'))->toBeTrue()
            ->and($service->isOverstocked('Depth', null))->toBeFalse();
    });

    it('answers false for a blank name without querying', function () {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        expect(app(OverstockService::class)->isOverstocked('  ', 'EU'))->toBeFalse()
            ->and($queries)->toBe(0);
    });
});

describe('OverstockService::overstockedGames()', function () {

    it('reports the numbers that triggered the flag', function () {
        seedOverstockedGame('Depth', 4, 'EU');
        seedStockedKey('Depth', ['region' => 'EU', 'sold_at' => now()->subDays(3)->toDateString()]);

        $report = app(OverstockService::class)->overstockedGames();

        expect($report)->toHaveCount(1)
            ->and($report[0]['name'])->toBe('Depth')
            ->and($report[0]['region'])->toBe('EU')
            ->and($report[0]['stock'])->toBe(4)
            ->and($report[0]['days_in_stock'])->toBe(OverstockPolicy::MIN_AGE_DAYS + 10)
            ->and($report[0]['sold_in_window'])->toBe(1)
            // 4 keys para 1 venda em 90 dias: 360 dias para escoar.
            ->and($report[0]['coverage_days'])->toBe(360);
    });

    it('rounds a fractional coverage up, so the listed days never read as within the limit', function () {
        // 7 keys para 4 vendas em 90 dias: 157,5 dias para escoar.
        seedOverstockedGame('Depth', 7);
        foreach (range(1, 4) as $i) {
            seedStockedKey('Depth', ['sold_at' => now()->subDays($i)->toDateString()]);
        }

        expect(app(OverstockService::class)->overstockedGames()[0]['coverage_days'])->toBe(158);
    });

    it('lists the same game once per overstocked region', function () {
        seedOverstockedGame('Portal', region: 'EU');
        seedOverstockedGame('Portal', region: null);

        $report = app(OverstockService::class)->overstockedGames();

        expect(array_column($report, 'region'))->toEqualCanonicalizing(['EU', null]);
    });

    it('reports a game without sales with a null coverage', function () {
        seedOverstockedGame('Minion Masters');

        expect(app(OverstockService::class)->overstockedGames()[0]['coverage_days'])->toBeNull();
    });

    it('omits games the rule does not flag', function () {
        seedStockedKey('Half-Life');

        expect(app(OverstockService::class)->overstockedGames())->toBe([]);
    });

    it('reads the clock once for the whole pass', function () {
        // Com `now()` relido por jogo, a lista poderia sinalizar por idade e
        // informar outra idade na mesma linha.
        seedOverstockedGame('Depth');
        $now = now()->addDays(30);

        $report = app(OverstockService::class)->overstockedGames($now);

        expect($report[0]['days_in_stock'])->toBe(OverstockPolicy::MIN_AGE_DAYS + 40);
    });

    it('orders the games from the largest stock down', function () {
        seedOverstockedGame('Curse of the Sea Rats', 8);
        seedOverstockedGame('Depth', 4);
        seedOverstockedGame('Sacred Gold', 6);

        expect(array_column(app(OverstockService::class)->overstockedGames(), 'name'))
            ->toBe(['Curse of the Sea Rats', 'Sacred Gold', 'Depth']);
    });
});
