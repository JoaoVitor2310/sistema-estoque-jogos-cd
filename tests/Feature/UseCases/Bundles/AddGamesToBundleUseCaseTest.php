<?php

/*
|--------------------------------------------------------------------------
| AddGamesToBundleUseCase — integration
|--------------------------------------------------------------------------
|
| Jogo repetido não é erro de banco — a pivot aceitaria a duplicata — mas é
| erro de operação: significa que a pessoa selecionou algo que já estava lá.
|
*/

use App\Models\Bundle;
use App\UseCases\Bundles\AddGamesToBundleUseCase;
use Illuminate\Support\Facades\DB;

function seedBundle(): Bundle
{
    return Bundle::create([
        'name' => 'Humble Choice Agosto',
        'type' => 'choice',
        'release_date' => '2026-08-01',
    ]);
}

function seedGameFor(string $name): int
{
    return DB::table('games')->insertGetId([
        'name' => $name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('links the games to the bundle', function () {
    $bundle = seedBundle();
    $gameIds = [seedGameFor('Portal 2'), seedGameFor('Half-Life')];

    $result = app(AddGamesToBundleUseCase::class)->execute($bundle, $gameIds);

    expect($result['added'])->toBeTrue()
        ->and(DB::table('bundle_games')->count())->toBe(2);
});

it('reports nothing added when every game is already linked', function () {
    $bundle = seedBundle();
    $gameId = seedGameFor('Portal 2');
    $bundle->games()->attach([$gameId]);

    $result = app(AddGamesToBundleUseCase::class)->execute($bundle, [$gameId]);

    expect($result['added'])->toBeFalse()
        ->and(DB::table('bundle_games')->count())->toBe(1);
});

it('links the new game of a batch that also carries an existing one', function () {
    $bundle = seedBundle();
    $linked = seedGameFor('Portal 2');
    $new = seedGameFor('Half-Life');
    $bundle->games()->attach([$linked]);

    $result = app(AddGamesToBundleUseCase::class)->execute($bundle, [$linked, $new]);

    expect($result['added'])->toBeTrue()
        ->and(DB::table('bundle_games')->count())->toBe(2);
});

it('never duplicates an existing link', function () {
    $bundle = seedBundle();
    $linked = seedGameFor('Portal 2');
    $new = seedGameFor('Half-Life');
    $bundle->games()->attach([$linked]);

    app(AddGamesToBundleUseCase::class)->execute($bundle, [$linked, $new]);

    expect(DB::table('bundle_games')->where('game_id', $linked)->count())->toBe(1);
});

it('returns the bundle with its games sorted by name', function () {
    $bundle = seedBundle();
    $gameIds = [seedGameFor('Zeta'), seedGameFor('Alfa')];

    $result = app(AddGamesToBundleUseCase::class)->execute($bundle, $gameIds);

    expect($result['bundle']->games->pluck('name')->all())->toBe(['Alfa', 'Zeta']);
});
