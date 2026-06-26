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

function supplierPayload(): array
{
    return ['steam_id' => '76561198000000001', 'url' => 'https://steamcommunity.com/id/seller'];
}

function profitableGame(): array
{
    return ['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => null];
}

describe('ProspectSupplierUseCase', function () {

    beforeEach(fn () => seedUseCaseDeps());

    describe('last_commented_at', function () {

        it('returns null when there is no previous commented trade for the list_code', function () {
            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierPayload(),
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
                'rows' => json_encode([['name' => 'Half-Life']]),
                'created_at' => now()->subWeek(),
                'updated_at' => now()->subWeek(),
            ]);

            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierPayload(),
                [profitableGame()],
                'G0eXM',
            );

            expect($result['last_commented_at']->toJSON())->toBe($commentedAt->toJSON());
        });

        it('ignores trades without last_commented_at', function () {
            DB::table('trades')->insert([
                'list_code' => 'G0eXM',
                'last_commented_at' => null,
                'rows' => json_encode([['name' => 'Half-Life']]),
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ]);

            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierPayload(),
                [profitableGame()],
                'G0eXM',
            );

            expect($result['last_commented_at'])->toBeNull();
        });

        it('returns null when list_code is not provided', function () {
            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierPayload(),
                [profitableGame()],
            );

            expect($result['last_commented_at'])->toBeNull();
        });
    });

    describe('games_changed', function () {

        it('returns false when there is no previous commented trade', function () {
            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierPayload(),
                [profitableGame()],
                'G0eXM',
            );

            expect($result['games_changed'])->toBeFalse();
        });

        it('returns false when game names are the same as the previous commented trade', function () {
            DB::table('trades')->insert([
                'list_code' => 'G0eXM',
                'last_commented_at' => now()->subWeek(),
                'rows' => json_encode([['name' => 'Half-Life']]),
                'created_at' => now()->subWeek(),
                'updated_at' => now()->subWeek(),
            ]);

            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierPayload(),
                [profitableGame()],
                'G0eXM',
            );

            expect($result['games_changed'])->toBeFalse();
        });

        it('returns true when game names differ from the previous commented trade', function () {
            DB::table('trades')->insert([
                'list_code' => 'G0eXM',
                'last_commented_at' => now()->subWeek(),
                'rows' => json_encode([['name' => 'Portal'], ['name' => 'Team Fortress 2']]),
                'created_at' => now()->subWeek(),
                'updated_at' => now()->subWeek(),
            ]);

            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierPayload(),
                [profitableGame()],
                'G0eXM',
            );

            expect($result['games_changed'])->toBeTrue();
        });

        it('returns false when list_code is not provided', function () {
            $result = app(ProspectSupplierUseCase::class)->execute(
                supplierPayload(),
                [profitableGame()],
            );

            expect($result['games_changed'])->toBeFalse();
        });
    });
});
