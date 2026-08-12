<?php

/*
|--------------------------------------------------------------------------
| UpdateGameUseCase — integration
|--------------------------------------------------------------------------
|
| Dois campos não são digitados e sim derivados: normalized_name acompanha o
| nome, e gamivo_id é procurado no estoque quando o formulário o deixa vazio.
|
*/

use App\Models\Game;
use App\UseCases\Games\UpdateGameUseCase;
use Illuminate\Support\Facades\DB;

function seedGame(array $attributes = []): Game
{
    return Game::create(array_merge([
        'name' => 'Portal 2',
        'normalized_name' => 'portal2',
        'region' => 'EU',
    ], $attributes));
}

it('persists the submitted fields', function () {
    $game = seedGame();

    app(UpdateGameUseCase::class)->execute($game, ['name' => 'Portal 3', 'popularity' => 42]);

    $fresh = DB::table('games')->find($game->id);

    expect($fresh->name)->toBe('Portal 3')
        ->and((int) $fresh->popularity)->toBe(42);
});

it('recalculates normalized_name when the name changes', function () {
    $game = seedGame();

    app(UpdateGameUseCase::class)->execute($game, ['name' => 'Half-Life: Alyx']);

    expect(DB::table('games')->find($game->id)->normalized_name)->not->toBe('portal2');
});

it('leaves normalized_name untouched when the name is absent', function () {
    // Regravar a partir de um nome ausente zeraria o campo que casa o jogo com
    // o price_researcher.
    $game = seedGame();

    app(UpdateGameUseCase::class)->execute($game, ['popularity' => 7]);

    expect(DB::table('games')->find($game->id)->normalized_name)->toBe('portal2');
});

it('fills an empty gamivo_id from another record of the same game', function () {
    seedGame(['region' => 'EU', 'gamivo_id' => '555444', 'name' => 'Portal 2']);
    $game = seedGame(['name' => 'Half-Life', 'region' => 'ROW']);

    app(UpdateGameUseCase::class)->execute($game, ['name' => 'Portal 2', 'region' => 'EU']);

    expect(DB::table('games')->find($game->id)->gamivo_id)->toBe('555444');
});

it('keeps the submitted gamivo_id instead of looking it up', function () {
    seedGame(['name' => 'Half-Life', 'region' => 'ROW', 'gamivo_id' => '555444']);
    $game = seedGame();

    app(UpdateGameUseCase::class)->execute($game, ['name' => 'Half-Life', 'region' => 'ROW', 'gamivo_id' => '999']);

    expect(DB::table('games')->find($game->id)->gamivo_id)->toBe('999');
});

it('returns the game with its bundles loaded', function () {
    $game = seedGame();

    $updated = app(UpdateGameUseCase::class)->execute($game, ['popularity' => 1]);

    expect($updated->relationLoaded('bundles'))->toBeTrue();
});
