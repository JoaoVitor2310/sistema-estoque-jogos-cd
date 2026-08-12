<?php

/*
|--------------------------------------------------------------------------
| CreateBundleUseCase — integration
|--------------------------------------------------------------------------
|
| O vínculo com os jogos é o que dá sentido ao bundle: sem ele, a janela de
| exclusão de 21 dias (KeyEligibility::BUNDLE_EXCLUSION_DAYS) não exclui key
| nenhuma. Por isso criação e attach vivem na mesma transação.
|
*/

use App\Models\Bundle;
use App\UseCases\Bundles\CreateBundleUseCase;
use Illuminate\Support\Facades\DB;

function seedBundleGame(string $name): int
{
    return DB::table('games')->insertGetId([
        'name' => $name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function bundlePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Humble Choice Agosto',
        'type' => 'choice',
        'release_date' => '2026-08-01',
    ], $overrides);
}

it('creates the bundle', function () {
    $bundle = app(CreateBundleUseCase::class)->execute(bundlePayload());

    expect($bundle->name)->toBe('Humble Choice Agosto')
        ->and(DB::table('bundles')->count())->toBe(1);
});

it('links the games that came with the payload', function () {
    $gameIds = [seedBundleGame('Portal 2'), seedBundleGame('Half-Life')];

    $bundle = app(CreateBundleUseCase::class)->execute(bundlePayload(['games' => $gameIds]));

    expect($bundle->games)->toHaveCount(2)
        ->and(DB::table('bundle_games')->count())->toBe(2);
});

it('does not persist games as a bundle column', function () {
    // `games` chega no mesmo payload, mas é pivot — passá-lo ao create()
    // estouraria por coluna inexistente.
    $gameIds = [seedBundleGame('Portal 2')];

    app(CreateBundleUseCase::class)->execute(bundlePayload(['games' => $gameIds]));

    expect(DB::table('bundles')->first())->not->toHaveProperty('games');
});

it('creates a bundle with no games at all', function () {
    $bundle = app(CreateBundleUseCase::class)->execute(bundlePayload());

    expect(DB::table('bundle_games')->count())->toBe(0)
        ->and($bundle->exists)->toBeTrue();
});

it('persists nothing when linking the games fails', function () {
    // Bundle gravado sem os jogos passaria despercebido: ele existe, mas não
    // exclui key nenhuma da venda.
    $gameIds = [seedBundleGame('Portal 2')];

    Bundle::created(function () {
        throw new RuntimeException('falha simulada depois do create');
    });

    try {
        app(CreateBundleUseCase::class)->execute(bundlePayload(['games' => $gameIds]));
    } catch (RuntimeException) {
        // A exceção é o gatilho; o que importa é o estado depois dela.
    }

    expect(DB::table('bundles')->count())->toBe(0)
        ->and(DB::table('bundle_games')->count())->toBe(0);

    Bundle::flushEventListeners();
});
