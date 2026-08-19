<?php

use App\Domain\Bundles\BundleGameLookup;
use App\Models\Trade;
use App\UseCases\Suppliers\ProspectSupplierUseCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BundleFactory;
use Tests\Support\TradeFactory;

function seedUseCaseDeps(float $tf2Price = 0.95): void
{
    Cache::flush();

    DB::table('fees')->insertOrIgnore([
        ['name' => 'gamivo_percent_low', 'preco' => 0.06, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_low', 'preco' => 0.25, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_percent_high', 'preco' => 0.08, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_high', 'preco' => 0.40, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('assets')->insertOrIgnore([
        'name' => 'TF2',
        'price_euro' => $tf2Price,
        'price_dollar' => 0.00,
        'price_brl' => 0.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function supplierSteamId(): string
{
    return '76561198000000001';
}

function profitableGame(): array
{
    return ['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => null];
}

describe('ProspectSupplierUseCase', function () {

    beforeEach(fn () => seedUseCaseDeps());

    it('creates trade with last_commented_at set when should_comment is true', function () {
        $result = app(ProspectSupplierUseCase::class)->execute(
            supplierSteamId(),
            [profitableGame()],
            'G0eXM',
        );

        expect($result['should_comment'])->toBeTrue();
        expect(DB::table('trades')->where('list_code', 'G0eXM')->whereNotNull('last_commented_at')->exists())->toBeTrue();
    });

    it('creates the trade with a delivery credential', function () {
        // Sem ela a trade chega na aba sem link e sem código para copiar.
        app(ProspectSupplierUseCase::class)->execute(
            supplierSteamId(),
            [profitableGame()],
            'G0eXM',
        );

        $trade = Trade::where('list_code', 'G0eXM')->sole();

        expect($trade->delivery_uuid)->not->toBeNull()
            ->and($trade->delivery_token)->not->toBeNull();
    });

    it('persists gamivo_id on the created trade line', function () {
        app(ProspectSupplierUseCase::class)->execute(
            supplierSteamId(),
            [['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => null, 'gamivo_id' => '144601']],
            'G0eXM',
        );

        $trade = Trade::where('list_code', 'G0eXM')->firstOrFail();

        expect($trade->lines)->toHaveCount(1)
            ->and($trade->lines[0]->gamivo_id)->toBe('144601')
            ->and($trade->lines[0]->game_name)->toBe('Half-Life');
    });

    it('does not create a trade when no games are profitable', function () {
        $result = app(ProspectSupplierUseCase::class)->execute(
            supplierSteamId(),
            [['name' => 'Junk Game', 'price_euro' => 0.05, 'popularity' => 1, 'region' => null]],
            'G0eXM',
        );

        expect($result['should_comment'])->toBeFalse();
        expect(DB::table('trades')->count())->toBe(0);
    });

    it('does not create a trade when within interval and games have not changed', function () {
        TradeFactory::withLines(['Half-Life'], [
            'list_code' => 'G0eXM',
            'last_commented_at' => now()->subDays(1),
        ]);

        $result = app(ProspectSupplierUseCase::class)->execute(
            supplierSteamId(),
            [profitableGame()],
            'G0eXM',
        );

        expect($result['should_comment'])->toBeFalse();
        expect(DB::table('trades')->count())->toBe(1);
    });

    describe('last_commented_at', function () {

        it('returns null when there is no previous commented trade for the list_code', function () {
            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierSteamId(),
                [profitableGame()],
                'G0eXM',
            );

            expect($result['last_commented_at'])->toBeNull();
        });

        it('returns the last_commented_at from the most recent commented trade', function () {
            $commentedAt = now()->subWeek()->startOfSecond();

            TradeFactory::withLines(['Half-Life'], [
                'list_code' => 'G0eXM',
                'last_commented_at' => $commentedAt,
            ]);

            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierSteamId(),
                [profitableGame()],
                'G0eXM',
            );

            expect($result['last_commented_at']->toJSON())->toBe($commentedAt->toJSON());
        });

        it('ignores trades without last_commented_at', function () {
            TradeFactory::withLines(['Half-Life'], [
                'list_code' => 'G0eXM',
                'last_commented_at' => null,
            ]);

            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierSteamId(),
                [profitableGame()],
                'G0eXM',
            );

            expect($result['last_commented_at'])->toBeNull();
        });

        it('returns null when list_code is not provided', function () {
            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierSteamId(),
                [profitableGame()],
            );

            expect($result['last_commented_at'])->toBeNull();
        });
    });

    describe('evaluateProfitability', function () {

        it('returns tf2_price for a profitable game', function () {
            // €4.50 com tier baixo (6% + €0.25): income = 4.50 * 0.94 - 0.25 = 3.98
            // tf2Offer = 3.98 * tier_ratio / 0.95
            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierSteamId(),
                [profitableGame()],
            );

            expect($result['profitable'])->toHaveCount(1);
            expect($result['profitable'][0])->toHaveKey('tf2_price');
            expect($result['profitable'][0]['tf2_price'])->toBeFloat()->toBeGreaterThan(0);
        });

        it('applies NEW_SUPPLIER_PROFIT_PERCENT (70%) margin to the offer', function () {
            // €4.50 tier baixo (6% + €0.25): income = 4.50 * 0.94 - 0.25 = 3.98
            // tf2Offer = 3.98 / (1 + 70/100) / 0.95 = 2.46
            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierSteamId(),
                [profitableGame()],
            );

            expect($result['profitable'][0]['tf2_price'])->toBe(2.46);
        });

        it('excludes games below profitability threshold', function () {
            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierSteamId(),
                [['name' => 'Junk', 'price_euro' => 0.01, 'popularity' => 1, 'region' => null]],
            );

            expect($result['profitable'])->toBeEmpty();
        });

        it('applies high fee tier for games priced at or above €8', function () {
            // €10.00 tier alto (8% + €0.40): income = 10.00 * 0.92 - 0.40 = 8.80
            // €4.50 tier baixo (6% + €0.25): income = 4.50 * 0.94 - 0.25 = 3.98
            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierSteamId(),
                [
                    ['name' => 'Cheap Game', 'price_euro' => 4.50, 'popularity' => 100, 'region' => null],
                    ['name' => 'Pricey Game', 'price_euro' => 10.00, 'popularity' => 100, 'region' => null],
                ],
            );

            expect($result['profitable'])->toHaveCount(2);

            $cheap = collect($result['profitable'])->firstWhere('name', 'Cheap Game');
            $pricey = collect($result['profitable'])->firstWhere('name', 'Pricey Game');

            // Tier alto gera income maior em termos absolutos
            expect($pricey['tf2_price'])->toBeGreaterThan($cheap['tf2_price']);
        });

        it('preserves region and popularity in profitable output', function () {
            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierSteamId(),
                [['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 999, 'region' => 'EU']],
            );

            expect($result['profitable'][0]['region'])->toBe('EU');
            expect($result['profitable'][0]['popularity'])->toBe(999);
        });

        it('preserves gamivo_id in profitable output when provided', function () {
            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierSteamId(),
                [['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => null, 'gamivo_id' => '144601']],
            );

            expect($result['profitable'][0]['gamivo_id'])->toBe('144601');
        });

        it('returns null gamivo_id when not provided', function () {
            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierSteamId(),
                [profitableGame()],
            );

            expect($result['profitable'][0]['gamivo_id'])->toBeNull();
        });
    });

    describe('total_tf2_price', function () {

        it('equals the tf2_price of a single profitable game', function () {
            // €4.50 → tf2_price 2.46 (ver teste de margem acima)
            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierSteamId(),
                [profitableGame()],
            );

            expect($result['total_tf2_price'])->toBe(2.46);
        });

        it('sums the tf2_price across all profitable games', function () {
            // €4.50 → 2.46 ; €10.00 → 5.45 ; soma = 7.91
            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierSteamId(),
                [
                    ['name' => 'Cheap Game', 'price_euro' => 4.50, 'popularity' => 100, 'region' => null],
                    ['name' => 'Pricey Game', 'price_euro' => 10.00, 'popularity' => 100, 'region' => null],
                ],
            );

            $expected = round(array_sum(array_column($result['profitable'], 'tf2_price')), 2);

            expect($result['total_tf2_price'])->toBe(7.91)->toBe($expected);
        });

        it('is 0.0 when no games are profitable', function () {
            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierSteamId(),
                [['name' => 'Junk', 'price_euro' => 0.01, 'popularity' => 1, 'region' => null]],
            );

            expect($result['profitable'])->toBeEmpty();
            expect($result['total_tf2_price'])->toBe(0.0);
        });
    });

    describe('games_changed', function () {

        it('returns false when there is no previous commented trade', function () {
            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierSteamId(),
                [profitableGame()],
                'G0eXM',
            );

            expect($result['games_changed'])->toBeFalse();
        });

        it('returns false when game names are the same as the previous commented trade', function () {
            TradeFactory::withLines(['Half-Life'], [
                'list_code' => 'G0eXM',
                'last_commented_at' => now()->subWeek(),
            ]);

            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierSteamId(),
                [profitableGame()],
                'G0eXM',
            );

            expect($result['games_changed'])->toBeFalse();
        });

        it('returns true when game names differ from the previous commented trade', function () {
            TradeFactory::withLines(['Portal', 'Team Fortress 2'], [
                'list_code' => 'G0eXM',
                'last_commented_at' => now()->subWeek(),
            ]);

            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierSteamId(),
                [profitableGame()],
                'G0eXM',
            );

            expect($result['games_changed'])->toBeTrue();
        });

        it('returns false when list_code is not provided', function () {
            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierSteamId(),
                [profitableGame()],
            );

            expect($result['games_changed'])->toBeFalse();
        });
    });
    describe('bundle lookup', function () {

        it('fills the bundle name when the game is in a recent bundle', function () {
            // Mesma resolução da lista comentada (ver StoreListTradeUseCase): a
            // prospecção passava mapa vazio e a coluna nascia nula, o que
            // deixava a trade prospectada sem a pista de region lock que a
            // página da entrega agora exibe.
            BundleFactory::withGame('Half-Life', 'Humble Choice Junho 2026', now()->subMonth()->toDateString());

            app(ProspectSupplierUseCase::class)->execute(supplierSteamId(), [profitableGame()], 'G0eXM');

            $trade = Trade::with('lines')->where('list_code', 'G0eXM')->first();

            expect($trade->lines->first()->bundle)->toBe('Humble Choice Junho 2026');
        });

        it('leaves the bundle null when the game is in no bundle', function () {
            app(ProspectSupplierUseCase::class)->execute(supplierSteamId(), [profitableGame()], 'G0eXM');

            $trade = Trade::with('lines')->where('list_code', 'G0eXM')->first();

            expect($trade->lines->first()->bundle)->toBeNull();
        });

        it('ignores a bundle older than the recent window', function () {
            BundleFactory::withGame(
                'Half-Life',
                'Bundle Antigo',
                now()->subMonths(BundleGameLookup::RECENT_MONTHS + 1)->toDateString(),
            );

            app(ProspectSupplierUseCase::class)->execute(supplierSteamId(), [profitableGame()], 'G0eXM');

            $trade = Trade::with('lines')->where('list_code', 'G0eXM')->first();

            expect($trade->lines->first()->bundle)->toBeNull();
        });

        it('does not look bundles up when there is nothing to comment', function () {
            // A prospecção avalia muitos perfis e comenta poucos: a consulta
            // fica dentro do `if`, senão custa uma query por perfil avaliado.
            BundleFactory::withGame('Half-Life', 'Humble Choice Junho 2026', now()->subMonth()->toDateString());

            $queries = 0;
            DB::listen(function ($query) use (&$queries) {
                if (str_contains($query->sql, 'bundle_games')) {
                    $queries++;
                }
            });

            // O jogo é lucrativo — o que segura o comentário é o intervalo.
            // Com a lista vazia o teste não provaria nada: `recentBundleByGameNames([])`
            // já sai sem consultar, e a query não aconteceria nem com a
            // chamada fora do `if`.
            TradeFactory::withLines(['Half-Life'], [
                'list_code' => 'G0eXM',
                'last_commented_at' => now()->subDays(1),
            ]);

            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierSteamId(),
                [profitableGame()],
                'G0eXM',
            );

            expect($result['should_comment'])->toBeFalse()
                ->and($queries)->toBe(0);
        });
    });
});
