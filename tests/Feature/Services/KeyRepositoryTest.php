<?php

/*
|--------------------------------------------------------------------------
| KeyRepository — characterization tests
|--------------------------------------------------------------------------
|
| Cobre as duas queries centrais do repositório:
|   - findByKeyCode: busca por código de ativação (com e sem excludeId)
|   - findEligibleForAutoSell: aplica as regras de elegibilidade para venda
|
| As regras de elegibilidade espelham os scopes do model Key
| e a constante BUNDLE_EXCLUSION_DAYS de KeyEligibility (21 dias).
|
*/

use App\Services\Keys\KeyRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedRepoFks(): void
{
    DB::table('suppliers')->insertOrIgnore(['id' => 1, 'supplier_url' => 'https://steamcommunity.com/id/seed']);
}

function insertRepoKey(array $overrides = []): int
{
    return DB::table('keys')->insertGetId(array_merge([
        'game_name' => 'Repo Test Game',
        'gamivo_id' => 'gam-'.uniqid(),
        'key_code' => 'REPO-KEY-'.uniqid(),
        'market_price' => 5.00,
        'individual_cost' => 2.00,
        'purchase_profit_percent' => 25.00,
        'supplier_url' => 'https://steamcommunity.com/id/test',
        'supplier_id' => 1,
        'claim_type' => 'Nenhuma',
        'key_format' => 'RK',
        'sell_platform' => 'Gamivo',
        'listed_at' => null,
        'sold_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('KeyRepository', function () {

    beforeEach(fn () => seedRepoFks());

    // ── findByKeyCode ─────────────────────────────────────────────────────────

    describe('findByKeyCode()', function () {

        it('returns the key when found by its activation code', function () {
            insertRepoKey(['key_code' => 'FIND-ME-12345']);

            $result = app(KeyRepository::class)->findByKeyCode('FIND-ME-12345');

            expect($result)->not->toBeNull()
                ->and($result->key_code)->toBe('FIND-ME-12345');
        });

        it('returns null when the key code does not exist', function () {
            $result = app(KeyRepository::class)->findByKeyCode('GHOST-KEY-99999');

            expect($result)->toBeNull();
        });

        it('excludes the own record when excludeId is provided', function () {
            // Dois registros com o mesmo código — ao excluir o id1, deve retornar id2
            $id1 = insertRepoKey(['key_code' => 'DUPE-CODE-001']);
            $id2 = insertRepoKey(['key_code' => 'DUPE-CODE-001']);

            $result = app(KeyRepository::class)->findByKeyCode('DUPE-CODE-001', $id1);

            expect($result)->not->toBeNull()
                ->and($result->id)->toBe($id2);
        });

        it('returns the record itself when excludeId refers to a different record', function () {
            $id1 = insertRepoKey(['key_code' => 'SOLO-CODE-001']);
            $id2 = insertRepoKey(['key_code' => 'ANOTHER-CODE-002']);

            // Excluindo id2, o registro id1 ainda deve aparecer
            $result = app(KeyRepository::class)->findByKeyCode('SOLO-CODE-001', $id2);

            expect($result)->not->toBeNull()
                ->and($result->id)->toBe($id1);
        });

        it('returns null when the only matching record is excluded by excludeId', function () {
            $id = insertRepoKey(['key_code' => 'ONLY-ONE-001']);

            $result = app(KeyRepository::class)->findByKeyCode('ONLY-ONE-001', $id);

            expect($result)->toBeNull();
        });
    });

    // ── findEligibleForAutoSell ───────────────────────────────────────────────

    describe('findEligibleForAutoSell()', function () {

        it('returns a key that meets all eligibility criteria', function () {
            insertRepoKey(['gamivo_id' => 'gam-eligible-001', 'key_code' => 'ELIG-KEY-001']);

            $results = app(KeyRepository::class)->findEligibleForAutoSell();

            expect($results->pluck('key_code'))->toContain('ELIG-KEY-001');
        });

        it('excludes a key without gamivo_id', function () {
            insertRepoKey(['gamivo_id' => null, 'key_code' => 'NO-GAMIVO-001']);

            $results = app(KeyRepository::class)->findEligibleForAutoSell();

            expect($results->pluck('key_code'))->not->toContain('NO-GAMIVO-001');
        });

        it('excludes a key with empty string gamivo_id', function () {
            insertRepoKey(['gamivo_id' => '', 'key_code' => 'EMPTY-GAMIVO-001']);

            $results = app(KeyRepository::class)->findEligibleForAutoSell();

            expect($results->pluck('key_code'))->not->toContain('EMPTY-GAMIVO-001');
        });

        it('excludes a key already listed for sale (listed_at set)', function () {
            insertRepoKey([
                'gamivo_id' => 'gam-listed-001',
                'key_code' => 'LISTED-KEY-001',
                'listed_at' => Carbon::now()->subDays(5)->toDateString(),
            ]);

            $results = app(KeyRepository::class)->findEligibleForAutoSell();

            expect($results->pluck('key_code'))->not->toContain('LISTED-KEY-001');
        });

        it('excludes a key already sold (sold_at set)', function () {
            insertRepoKey([
                'gamivo_id' => 'gam-sold-001',
                'key_code' => 'SOLD-REPO-001',
                'sold_at' => Carbon::now()->subDays(10)->toDateString(),
            ]);

            $results = app(KeyRepository::class)->findEligibleForAutoSell();

            expect($results->pluck('key_code'))->not->toContain('SOLD-REPO-001');
        });

        it('excludes gift links (key_code containing "http")', function () {
            insertRepoKey([
                'gamivo_id' => 'gam-gift-001',
                'key_code' => 'https://store.steampowered.com/gift/abc123',
            ]);

            $results = app(KeyRepository::class)->findEligibleForAutoSell();

            $links = $results->pluck('key_code')->filter(fn ($k) => str_contains($k, 'http'));
            expect($links)->toBeEmpty();
        });

        it('excludes a key whose game is in a bundle released less than 21 days ago', function () {
            $keyCode = 'RECENT-BUNDLE-KEY';
            $gamivoId = 'gam-recent-001';

            insertRepoKey(['gamivo_id' => $gamivoId, 'key_code' => $keyCode]);

            $gameId = DB::table('games')->insertGetId(['name' => 'Recent Game', 'id_gamivo' => $gamivoId, 'created_at' => now(), 'updated_at' => now()]);
            $bundleId = DB::table('bundles')->insertGetId(['name' => 'Recent Bundle', 'type' => 'bundle', 'release_date' => Carbon::now()->subDays(10)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('bundle_games')->insert(['bundle_id' => $bundleId, 'game_id' => $gameId, 'created_at' => now(), 'updated_at' => now()]);

            $results = app(KeyRepository::class)->findEligibleForAutoSell();

            expect($results->pluck('key_code'))->not->toContain($keyCode);
        });

        it('includes a key whose game is in a bundle released more than 21 days ago', function () {
            $keyCode = 'OLD-BUNDLE-KEY';
            $gamivoId = 'gam-old-001';

            insertRepoKey(['gamivo_id' => $gamivoId, 'key_code' => $keyCode]);

            $gameId = DB::table('games')->insertGetId(['name' => 'Old Bundle Game', 'id_gamivo' => $gamivoId, 'created_at' => now(), 'updated_at' => now()]);
            $bundleId = DB::table('bundles')->insertGetId(['name' => 'Old Bundle', 'type' => 'bundle', 'release_date' => Carbon::now()->subDays(30)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('bundle_games')->insert(['bundle_id' => $bundleId, 'game_id' => $gameId, 'created_at' => now(), 'updated_at' => now()]);

            $results = app(KeyRepository::class)->findEligibleForAutoSell();

            expect($results->pluck('key_code'))->toContain($keyCode);
        });

        it('returns only eligible keys when mixed statuses coexist', function () {
            insertRepoKey(['gamivo_id' => 'gam-ok-1', 'key_code' => 'OK-KEY-001']);
            insertRepoKey(['gamivo_id' => 'gam-ok-2', 'key_code' => 'OK-KEY-002']);
            insertRepoKey(['gamivo_id' => null,        'key_code' => 'NO-ID-KEY-001']);
            insertRepoKey(['gamivo_id' => 'gam-sold',  'key_code' => 'SOLD-MIX-001', 'sold_at' => now()->toDateString()]);

            $results = app(KeyRepository::class)->findEligibleForAutoSell();
            $codes = $results->pluck('key_code');

            expect($codes)->toContain('OK-KEY-001')
                ->and($codes)->toContain('OK-KEY-002')
                ->and($codes)->not->toContain('NO-ID-KEY-001')
                ->and($codes)->not->toContain('SOLD-MIX-001');
        });
    });
});
