<?php

/*
|--------------------------------------------------------------------------
| BundleService::findIdByName — o bundle da compra direta, pelo título
|--------------------------------------------------------------------------
|
| Casamento exato (com trim), só bundle vivo, lançamento mais recente em caso
| de nome repetido. Os outros métodos do service (bundleByTitle,
| recentBundleByGameNames) são exercitados pelos UseCases que os usam.
|
*/

use App\Services\Bundles\BundleService;
use Illuminate\Support\Facades\DB;

function insertBundle(array $attrs): int
{
    return DB::table('bundles')->insertGetId(array_merge([
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
}

describe('BundleService::findIdByName', function () {

    it('finds the bundle whose name is exactly the given one', function () {
        $id = insertBundle(['name' => 'Humble Choice September']);

        expect(app(BundleService::class)->findIdByName('Humble Choice September'))->toBe($id);
    });

    it('ignores surrounding whitespace in the given name', function () {
        $id = insertBundle(['name' => 'Humble Choice September']);

        expect(app(BundleService::class)->findIdByName('  Humble Choice September '))->toBe($id);
    });

    it('does not match a partial name', function () {
        insertBundle(['name' => 'Humble Choice September']);

        expect(app(BundleService::class)->findIdByName('Humble Choice'))->toBeNull();
    });

    it('returns null for a blank name without matching a bundle with no name', function () {
        insertBundle(['name' => null]);

        expect(app(BundleService::class)->findIdByName(null))->toBeNull()
            ->and(app(BundleService::class)->findIdByName('   '))->toBeNull();
    });

    it('does not match a soft deleted bundle', function () {
        insertBundle(['name' => 'Humble Choice September', 'deleted_at' => now()]);

        expect(app(BundleService::class)->findIdByName('Humble Choice September'))->toBeNull();
    });

    it('prefers the latest release when two bundles share the name', function () {
        insertBundle(['name' => 'Humble Choice', 'release_date' => '2025-09-01']);
        $latest = insertBundle(['name' => 'Humble Choice', 'release_date' => '2026-09-01']);
        insertBundle(['name' => 'Humble Choice', 'release_date' => null]);

        expect(app(BundleService::class)->findIdByName('Humble Choice'))->toBe($latest);
    });
});
