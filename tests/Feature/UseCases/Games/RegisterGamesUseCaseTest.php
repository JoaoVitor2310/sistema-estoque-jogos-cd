<?php

/*
|--------------------------------------------------------------------------
| RegisterGamesUseCase — integration
|--------------------------------------------------------------------------
|
| Duas regras carregam o peso: duplicata (nome + região) é pulada em vez de
| abortar o lote, e o gamivo_id ausente é procurado no estoque antes de o jogo
| nascer sem vínculo com a oferta do marketplace.
|
*/

use App\Models\Game;
use App\UseCases\Games\RegisterGamesUseCase;
use Illuminate\Support\Facades\DB;

// O teste de atomicidade registra um listener de `creating` no model; sem isso
// ele vazaria para os testes seguintes do processo.
afterEach(fn () => Game::flushEventListeners());

it('creates the games it received', function () {
    $result = app(RegisterGamesUseCase::class)->execute([
        ['name' => 'Portal 2', 'region' => 'EU'],
        ['name' => 'Half-Life', 'region' => 'EU'],
    ]);

    expect($result['created'])->toHaveCount(2)
        ->and($result['skipped'])->toBe([])
        ->and(DB::table('games')->count())->toBe(2);
});

it('normalizes the name on creation', function () {
    // normalized_name é o que casa o jogo com o price_researcher; nasce derivado
    // do nome, nunca digitado.
    app(RegisterGamesUseCase::class)->execute([
        ['name' => 'Grand Theft Auto: San Andreas', 'region' => 'EU'],
    ]);

    expect(DB::table('games')->value('normalized_name'))
        ->not->toBeNull()
        ->not->toBe('Grand Theft Auto: San Andreas');
});

it('skips a game already registered for the same region', function () {
    DB::table('games')->insert([
        'name' => 'Portal 2',
        'region' => 'EU',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = app(RegisterGamesUseCase::class)->execute([
        ['name' => 'Portal 2', 'region' => 'EU'],
        ['name' => 'Half-Life', 'region' => 'EU'],
    ]);

    expect($result['skipped'])->toBe(['Portal 2'])
        ->and($result['created'])->toHaveCount(1)
        ->and(DB::table('games')->count())->toBe(2);
});

it('registers the same game for a different region', function () {
    // Região é parte da identidade: a mesma key não serve nos dois mercados.
    DB::table('games')->insert([
        'name' => 'Portal 2',
        'region' => 'EU',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = app(RegisterGamesUseCase::class)->execute([
        ['name' => 'Portal 2', 'region' => 'ROW'],
    ]);

    expect($result['skipped'])->toBe([])
        ->and(DB::table('games')->count())->toBe(2);
});

it('inherits gamivo_id from an existing key of the same game', function () {
    DB::table('suppliers')->insert([
        'id' => 1,
        'url' => 'https://steamcommunity.com/id/seed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('keys')->insert([
        'key_code' => 'AAAAA-BBBBB-CCCCC',
        'supplier_id' => 1,
        'supplier_url' => 'https://steamcommunity.com/id/seed',
        'game_name' => 'Portal 2',
        'region' => 'EU',
        'gamivo_id' => '778899',
        'market_price' => 10,
        'individual_cost' => 5,
        'min_api' => 7,
        'max_api' => 40,
        'acquired_at' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(RegisterGamesUseCase::class)->execute([
        ['name' => 'Portal 2', 'region' => 'EU'],
    ]);

    expect(DB::table('games')->value('gamivo_id'))->toBe('778899');
});

it('keeps the submitted gamivo_id instead of looking it up', function () {
    DB::table('games')->insert([
        'name' => 'Portal 2',
        'region' => 'ROW',
        'gamivo_id' => '111111',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(RegisterGamesUseCase::class)->execute([
        ['name' => 'Portal 2', 'region' => 'EU', 'gamivo_id' => '222222'],
    ]);

    expect(Game::where('region', 'EU')->value('gamivo_id'))->toBe('222222');
});

it('persists nothing when one game of the batch fails', function () {
    // Lote meio gravado deixaria o operador sem saber o que reenviar. A falha é
    // forçada por evento porque o SQLite de teste não recusa nada que o Postgres
    // recusaria nesta tabela — não há coluna NOT NULL para violar.
    Game::creating(function (Game $game) {
        if ($game->name === 'Broken Game') {
            throw new RuntimeException('falha simulada no meio do lote');
        }
    });

    try {
        app(RegisterGamesUseCase::class)->execute([
            ['name' => 'Portal 2', 'region' => 'EU'],
            ['name' => 'Broken Game', 'region' => 'EU'],
        ]);
    } catch (RuntimeException) {
        // A exceção é o gatilho; o que importa é o estado depois dela.
    }

    expect(DB::table('games')->count())->toBe(0);
});
