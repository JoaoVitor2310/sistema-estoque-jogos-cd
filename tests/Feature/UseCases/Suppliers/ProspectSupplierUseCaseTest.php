<?php

use App\UseCases\Suppliers\ProspectSupplierUseCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        DB::table('trades')->insert([
            'list_code' => 'G0eXM',
            'last_commented_at' => now()->subDays(1),
            'games' => json_encode([['name' => 'Half-Life']]),
            'created_at' => now()->subDays(1),
            'updated_at' => now()->subDays(1),
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

            DB::table('trades')->insert([
                'list_code' => 'G0eXM',
                'last_commented_at' => $commentedAt,
                'games' => json_encode([['name' => 'Half-Life']]),
                'created_at' => now()->subWeek(),
                'updated_at' => now()->subWeek(),
            ]);

            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierSteamId(),
                [profitableGame()],
                'G0eXM',
            );

            expect($result['last_commented_at']->toJSON())->toBe($commentedAt->toJSON());
        });

        it('ignores trades without last_commented_at', function () {
            DB::table('trades')->insert([
                'list_code' => 'G0eXM',
                'last_commented_at' => null,
                'games' => json_encode([['name' => 'Half-Life']]),
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
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
            DB::table('trades')->insert([
                'list_code' => 'G0eXM',
                'last_commented_at' => now()->subWeek(),
                'games' => json_encode([['name' => 'Half-Life']]),
                'created_at' => now()->subWeek(),
                'updated_at' => now()->subWeek(),
            ]);

            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierSteamId(),
                [profitableGame()],
                'G0eXM',
            );

            expect($result['games_changed'])->toBeFalse();
        });

        it('returns true when game names differ from the previous commented trade', function () {
            DB::table('trades')->insert([
                'list_code' => 'G0eXM',
                'last_commented_at' => now()->subWeek(),
                'games' => json_encode([['name' => 'Portal'], ['name' => 'Team Fortress 2']]),
                'created_at' => now()->subWeek(),
                'updated_at' => now()->subWeek(),
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
});
