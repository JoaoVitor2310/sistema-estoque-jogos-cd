<?php

/*
|--------------------------------------------------------------------------
| AutoSell — characterization tests
|--------------------------------------------------------------------------
|
| Covers KeySaleController::autoSell()
| Route: GET /keys/auto-sell  (no auth — withoutMiddleware)
| Response shape: { "statusCode": 200, "message": "...", "data": [...] }
|
*/

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Insert a key that is eligible by default.
 * Pass $overrides to break specific eligibility rules.
 */
function createKey(array $overrides = []): void
{
    DB::table('keys')->insert(array_merge([
        'game_name' => 'Test Game',
        'gamivo_id' => 'gam-'.uniqid(),
        'key_code' => 'ABCDE-12345-FGHIJ',
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

function idGamivoList(array $data): array
{
    return array_column($data, 'idGamivo');
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('GET /keys/auto-sell', function () {

    beforeEach(function () {
        Config::set('services.external_secret', 'test-secret');

        // Formulas is injected into the controller constructor and queries taxas
        DB::table('fees')->insert([
            ['name' => 'gamivo_percent_low', 'preco' => 0.060, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'gamivo_fixed_low',       'preco' => 0.250, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'gamivo_percent_high', 'preco' => 0.080, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'gamivo_fixed_high',       'preco' => 0.400, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('suppliers')->insert(['id' => 1, 'supplier_url' => 'https://steamcommunity.com/id/seed']);
    });

    // ── Happy path ────────────────────────────────────────────────────────────

    it('returns an eligible key in the listing', function () {
        createKey(['gamivo_id' => 'gam-eligible-001']);

        $data = $this->withToken('test-secret')->getJson('/keys/auto-sell')->assertOk()->json('data');

        expect(idGamivoList($data))->toContain('gam-eligible-001');
    });

    // ── Exclusion rules ───────────────────────────────────────────────────────

    describe('excludes a key when', function () {

        it('gamivo_id is null', function () {
            createKey(['gamivo_id' => null]);

            $data = $this->withToken('test-secret')->getJson('/keys/auto-sell')->assertOk()->json('data');

            expect($data)->toHaveCount(0);
        });

        it('gamivo_id is an empty string', function () {
            createKey(['gamivo_id' => '']);

            $data = $this->withToken('test-secret')->getJson('/keys/auto-sell')->assertOk()->json('data');

            expect($data)->toHaveCount(0);
        });

        it('the key has already been listed for sale (listed_at is set)', function () {
            createKey([
                'gamivo_id' => 'gam-listed',
                'listed_at' => Carbon::now()->subDays(5)->toDateString(),
            ]);

            $data = $this->withToken('test-secret')->getJson('/keys/auto-sell')->assertOk()->json('data');

            expect(idGamivoList($data))->not->toContain('gam-listed');
        });

        it('the key has already been sold (sold_at is set)', function () {
            createKey([
                'gamivo_id' => 'gam-sold',
                'sold_at' => Carbon::now()->subDays(10)->toDateString(),
            ]);

            $data = $this->withToken('test-secret')->getJson('/keys/auto-sell')->assertOk()->json('data');

            expect(idGamivoList($data))->not->toContain('gam-sold');
        });

        it('key_code contains "http" (gift link)', function () {
            createKey([
                'gamivo_id' => 'gam-gift',
                'key_code' => 'https://store.steampowered.com/gift/abc123',
            ]);

            $data = $this->withToken('test-secret')->getJson('/keys/auto-sell')->assertOk()->json('data');

            expect(idGamivoList($data))->not->toContain('gam-gift');
        });
    });

    // ── 21-day bundle rule ────────────────────────────────────────────────────

    describe('21-day bundle rule', function () {

        it('excludes a key whose game is in a bundle released less than 21 days ago', function () {
            $gamivoId = 'gam-recent-bundle';
            createKey(['gamivo_id' => $gamivoId]);

            $gameId = DB::table('games')->insertGetId([
                'name' => 'Recent Bundle Game', 'gamivo_id' => $gamivoId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $bundleId = DB::table('bundles')->insertGetId([
                'name' => 'Recent Bundle', 'type' => 'bundle',
                'release_date' => Carbon::now()->subDays(10)->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('bundle_games')->insert([
                'bundle_id' => $bundleId, 'game_id' => $gameId,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $data = $this->withToken('test-secret')->getJson('/keys/auto-sell')->assertOk()->json('data');

            expect(idGamivoList($data))->not->toContain($gamivoId);
        });

        it('includes a key whose game is in a bundle released more than 21 days ago', function () {
            $gamivoId = 'gam-old-bundle';
            createKey(['gamivo_id' => $gamivoId]);

            $gameId = DB::table('games')->insertGetId([
                'name' => 'Old Bundle Game', 'gamivo_id' => $gamivoId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $bundleId = DB::table('bundles')->insertGetId([
                'name' => 'Old Bundle', 'type' => 'bundle',
                'release_date' => Carbon::now()->subDays(30)->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('bundle_games')->insert([
                'bundle_id' => $bundleId, 'game_id' => $gameId,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $data = $this->withToken('test-secret')->getJson('/keys/auto-sell')->assertOk()->json('data');

            expect(idGamivoList($data))->toContain($gamivoId);
        });

        it('still excludes a key when the bundle was released exactly 20 days ago', function () {
            // The query uses: release_date > now()->subDays(21)
            // 20 days ago IS inside the window, so the key must be excluded.
            $gamivoId = 'gam-20-days';
            createKey(['gamivo_id' => $gamivoId]);

            $gameId = DB::table('games')->insertGetId([
                'name' => '20-Day Game', 'gamivo_id' => $gamivoId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $bundleId = DB::table('bundles')->insertGetId([
                'name' => '20-Day Bundle', 'type' => 'choice',
                'release_date' => Carbon::now()->subDays(20)->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('bundle_games')->insert([
                'bundle_id' => $bundleId, 'game_id' => $gameId,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $data = $this->withToken('test-secret')->getJson('/keys/auto-sell')->assertOk()->json('data');

            expect(idGamivoList($data))->not->toContain($gamivoId);
        });
    });

    // ── Combined ──────────────────────────────────────────────────────────────

    it('returns only eligible keys when multiple keys with different statuses exist', function () {
        createKey(['gamivo_id' => 'gam-ok-1']);
        createKey(['gamivo_id' => null]);
        createKey(['gamivo_id' => 'gam-sold', 'sold_at' => Carbon::now()->subDays(1)->toDateString()]);
        createKey(['gamivo_id' => 'gam-ok-2']);

        $data = $this->withToken('test-secret')->getJson('/keys/auto-sell')->assertOk()->json('data');

        expect($data)->toHaveCount(2)
            ->and(idGamivoList($data))->toContain('gam-ok-1')
            ->and(idGamivoList($data))->toContain('gam-ok-2')
            ->and(idGamivoList($data))->not->toContain('gam-sold');
    });
});
