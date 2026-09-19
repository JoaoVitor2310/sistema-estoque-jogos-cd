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

use App\Domain\Trades\OverstockPolicy;
use App\Services\Keys\KeyRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedRepoFks(): void
{
    DB::table('suppliers')->insertOrIgnore(['id' => 1, 'url' => 'https://steamcommunity.com/id/seed']);
}

function insertRepoKey(array $overrides = []): int
{
    return DB::table('keys')->insertGetId(array_merge([
        'game_name' => 'Repo Test Game',
        'gamivo_id' => 'gam-'.uniqid(),
        'key_code' => 'REPO-KEY-'.uniqid(),
        'market_price' => 5.00,
        'individual_cost' => 2.00,
        'min_api' => 1.00,
        'max_api' => 10.00,
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

            $gameId = DB::table('games')->insertGetId(['name' => 'Recent Game', 'gamivo_id' => $gamivoId, 'created_at' => now(), 'updated_at' => now()]);
            $bundleId = DB::table('bundles')->insertGetId(['name' => 'Recent Bundle', 'type' => 'bundle', 'release_date' => Carbon::now()->subDays(10)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('bundle_games')->insert(['bundle_id' => $bundleId, 'game_id' => $gameId, 'created_at' => now(), 'updated_at' => now()]);

            $results = app(KeyRepository::class)->findEligibleForAutoSell();

            expect($results->pluck('key_code'))->not->toContain($keyCode);
        });

        it('includes a key whose game is in a bundle released more than 21 days ago', function () {
            $keyCode = 'OLD-BUNDLE-KEY';
            $gamivoId = 'gam-old-001';

            insertRepoKey(['gamivo_id' => $gamivoId, 'key_code' => $keyCode]);

            $gameId = DB::table('games')->insertGetId(['name' => 'Old Bundle Game', 'gamivo_id' => $gamivoId, 'created_at' => now(), 'updated_at' => now()]);
            $bundleId = DB::table('bundles')->insertGetId(['name' => 'Old Bundle', 'type' => 'bundle', 'release_date' => Carbon::now()->subDays(30)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('bundle_games')->insert(['bundle_id' => $bundleId, 'game_id' => $gameId, 'created_at' => now(), 'updated_at' => now()]);

            $results = app(KeyRepository::class)->findEligibleForAutoSell();

            expect($results->pluck('key_code'))->toContain($keyCode);
        });

        it('returns eligible keys ordered by id ASC (FIFO for auto-sell grouping)', function () {
            // A Gamivo vende FIFO; o AutoSellUseCase usa a key de menor id como governante do grupo.
            $id1 = insertRepoKey(['gamivo_id' => 'gam-order', 'key_code' => 'ORDER-1']);
            $id2 = insertRepoKey(['gamivo_id' => 'gam-order', 'key_code' => 'ORDER-2']);
            $id3 = insertRepoKey(['gamivo_id' => 'gam-order', 'key_code' => 'ORDER-3']);

            $ids = app(KeyRepository::class)->findEligibleForAutoSell()
                ->where('gamivo_id', 'gam-order')
                ->pluck('id')
                ->values()
                ->all();

            expect($ids)->toBe([$id1, $id2, $id3]);
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

    // ── findGoverningKeyByGamivoId ────────────────────────────────────────────

    describe('findGoverningKeyByGamivoId()', function () {

        it('returns the key with the earliest listed_at among listed, unsold keys sharing gamivo_id', function () {
            insertRepoKey([
                'gamivo_id' => '900001',
                'key_code' => 'NEWER-LISTED',
                'listed_at' => Carbon::now()->subDays(2)->toDateString(),
            ]);
            $olderId = insertRepoKey([
                'gamivo_id' => '900001',
                'key_code' => 'OLDER-LISTED',
                'listed_at' => Carbon::now()->subDays(10)->toDateString(),
            ]);

            $governing = app(KeyRepository::class)->findGoverningKeyByGamivoId(900001);

            expect($governing)->not->toBeNull()
                ->and($governing->id)->toBe($olderId)
                ->and($governing->key_code)->toBe('OLDER-LISTED');
        });

        it('breaks a tie on the same listed_at date by the smallest id, mirroring the batch upload order', function () {
            // listed_at is a `date` column (no time component), so both rows below
            // tie exactly — the normal case for keys confirmed in the same
            // AutoSellUseCase batch. The smaller id was uploaded first in that
            // batch (uploadKeys receives keys sorted by id ASC), so it must win.
            $sameDate = Carbon::now()->subDays(3)->toDateString();

            $smallerId = insertRepoKey(['gamivo_id' => '900002', 'key_code' => 'TIE-SMALLER-ID', 'listed_at' => $sameDate]);
            insertRepoKey(['gamivo_id' => '900002', 'key_code' => 'TIE-LARGER-ID', 'listed_at' => $sameDate]);

            $governing = app(KeyRepository::class)->findGoverningKeyByGamivoId(900002);

            expect($governing->id)->toBe($smallerId);
        });

        it('excludes keys that are not yet listed', function () {
            insertRepoKey(['gamivo_id' => '900003', 'key_code' => 'NOT-LISTED', 'listed_at' => null]);
            $listedId = insertRepoKey([
                'gamivo_id' => '900003',
                'key_code' => 'IS-LISTED',
                'listed_at' => Carbon::now()->subDay()->toDateString(),
            ]);

            $governing = app(KeyRepository::class)->findGoverningKeyByGamivoId(900003);

            expect($governing->id)->toBe($listedId);
        });

        it('excludes keys already sold', function () {
            insertRepoKey([
                'gamivo_id' => '900004',
                'key_code' => 'SOLD-GOV',
                'listed_at' => Carbon::now()->subDays(5)->toDateString(),
                'sold_at' => Carbon::now()->toDateString(),
            ]);

            $governing = app(KeyRepository::class)->findGoverningKeyByGamivoId(900004);

            expect($governing)->toBeNull();
        });

        it('returns null when no key shares the gamivo_id', function () {
            $governing = app(KeyRepository::class)->findGoverningKeyByGamivoId(900999);

            expect($governing)->toBeNull();
        });

    });
});

// ── stockByGameIdentity ─────────────────────────────────────────────────────

describe('KeyRepository::stockByGameIdentity()', function () {

    beforeEach(fn () => seedRepoFks());

    it('counts unsold keys of a game and the date of the oldest one', function () {
        insertRepoKey(['game_name' => 'Depth', 'acquired_at' => now()->subDays(94)->toDateString()]);
        insertRepoKey(['game_name' => 'Depth', 'acquired_at' => now()->subDays(10)->toDateString()]);

        $stock = app(KeyRepository::class)->stockByGameIdentity()['depth|'];

        expect($stock->stock)->toBe(2)
            ->and($stock->displayName)->toBe('Depth')
            ->and($stock->oldestAcquiredAt->toDateString())->toBe(now()->subDays(94)->toDateString())
            ->and($stock->soldInWindow)->toBe(0);
    });

    it('merges spellings that normalize to the same game', function () {
        insertRepoKey(['game_name' => 'Alien Shooter 2: Reloaded', 'acquired_at' => now()->subDays(178)->toDateString()]);
        insertRepoKey(['game_name' => 'Alien Shooter 2 Reloaded', 'acquired_at' => now()->subDays(20)->toDateString()]);

        $stock = app(KeyRepository::class)->stockByGameIdentity();

        expect($stock['alien shooter 2 reloaded|']->stock)->toBe(2)
            ->and($stock['alien shooter 2 reloaded|']->oldestAcquiredAt->toDateString())
            ->toBe(now()->subDays(178)->toDateString());
    });

    it('counts sales inside the window and ignores older ones', function () {
        insertRepoKey(['game_name' => 'Warpips', 'sold_at' => now()->subDays(10)->toDateString()]);
        insertRepoKey(['game_name' => 'Warpips', 'sold_at' => now()->subDays(200)->toDateString()]);
        insertRepoKey(['game_name' => 'Warpips', 'acquired_at' => now()->subDays(300)->toDateString()]);

        $stock = app(KeyRepository::class)->stockByGameIdentity()['warpips|'];

        expect($stock->stock)->toBe(1)
            ->and($stock->soldInWindow)->toBe(1);
    });

    it('keeps the same game apart in each region', function () {
        // Estoque parado em ROW não vira venda em EU: somar os dois deixaria
        // um EU que vende esconder um ROW encalhado.
        insertRepoKey(['game_name' => 'Portal', 'region' => 'EU']);
        insertRepoKey(['game_name' => 'Portal', 'region' => 'ROW']);
        insertRepoKey(['game_name' => 'Portal', 'region' => 'ROW']);

        $stock = app(KeyRepository::class)->stockByGameIdentity();

        expect($stock['portal|EU']->stock)->toBe(1)
            ->and($stock['portal|ROW']->stock)->toBe(2);
    });

    it('groups keys without region as the global region, apart from the written ones', function () {
        insertRepoKey(['game_name' => 'Portal', 'region' => null]);
        insertRepoKey(['game_name' => 'Portal', 'region' => 'EU']);

        $stock = app(KeyRepository::class)->stockByGameIdentity();

        expect($stock['portal|']->stock)->toBe(1)
            ->and($stock['portal|']->region)->toBeNull()
            ->and($stock['portal|EU']->stock)->toBe(1);
    });

    it('merges a region written in different case', function () {
        insertRepoKey(['game_name' => 'Portal', 'region' => 'LATAM']);
        insertRepoKey(['game_name' => 'Portal', 'region' => 'latam']);

        expect(app(KeyRepository::class)->stockByGameIdentity()['portal|LATAM']->stock)->toBe(2);
    });

    it('ignores soft-deleted keys', function () {
        $id = insertRepoKey(['game_name' => 'Apagado', 'acquired_at' => now()->subDays(90)->toDateString()]);
        DB::table('keys')->where('id', $id)->update(['deleted_at' => now()]);

        expect(app(KeyRepository::class)->stockByGameIdentity())->not->toHaveKey('apagado|');
    });

    it('counts a sale exactly on the window edge', function () {
        // Fronteira do `sold_at >= ?`: a janela de 90 dias é hoje e os 89
        // anteriores; o 90º dia para trás já é o 91º e fica de fora. É o que
        // separa jogo "sem venda nenhuma" de jogo com ritmo.
        $now = Carbon::parse('2026-06-15 10:00:00');
        insertRepoKey(['game_name' => 'Na Borda', 'sold_at' => $now->copy()->subDays(OverstockPolicy::SALES_WINDOW_DAYS - 1)->toDateString()]);
        insertRepoKey(['game_name' => 'Na Borda', 'sold_at' => $now->copy()->subDays(OverstockPolicy::SALES_WINDOW_DAYS)->toDateString()]);

        $stock = app(KeyRepository::class)->stockByGameIdentity($now)['na borda|'];

        expect($stock->soldInWindow)->toBe(1);
    });

    it('aggregates without the Postgres-only FILTER clause', function () {
        // Produção é Postgres e a suíte roda em SQLite: `COUNT(*) FILTER` passa
        // aqui em versões recentes e quebraria calado no dia em que alguém
        // "simplificasse" a agregação. Ver docs/agents/testing.md.
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        app(KeyRepository::class)->stockByGameIdentity();

        $aggregate = collect($statements)->first(fn (string $sql) => str_contains($sql, 'oldest_acquired_at'));

        $this->assertNotNull($aggregate, 'a query de estoque não foi executada');
        $this->assertStringNotContainsString('FILTER (', $aggregate, 'agregação voltou a usar FILTER, que não roda em SQLite');
        $this->assertStringContainsString('CASE WHEN', $aggregate);
    });

    it('falls back to created_at when the key has no acquisition date', function () {
        // Keys antigas não têm acquired_at; sem o fallback, estoque velho
        // pareceria sem idade e escaparia da regra de encalhe.
        insertRepoKey([
            'game_name' => 'Sem Data',
            'acquired_at' => null,
            'created_at' => now()->subDays(400),
        ]);

        $stock = app(KeyRepository::class)->stockByGameIdentity()['sem data|'];

        expect($stock->oldestAcquiredAt->toDateString())->toBe(now()->subDays(400)->toDateString());
    });
});
