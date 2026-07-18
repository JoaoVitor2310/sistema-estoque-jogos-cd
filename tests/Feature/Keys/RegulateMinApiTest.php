<?php

/*
|--------------------------------------------------------------------------
| RegulateMinApiUseCase — feature tests
|--------------------------------------------------------------------------
|
| Cobre o recálculo diário de min_api para todas as keys não vendidas
| (listadas ou não), a partir de MinimumMarginPolicy — fonte única do piso.
|
| Regras verificadas (ver MinimumMarginPolicy para a árvore completa):
|  1. Não listada — decaimento por acquired_at (UNLISTED_AGING_MONTHS/UNLISTED_MODERATE_MONTHS)
|  2. Listada — decaimento por listed_at (LISTED_AGING_MONTHS/LISTED_MODERATE_MONTHS/LISTED_EARLY_MONTHS)
|  3. FLOOR incondicional — comprada >= OLD_KEY_MONTHS (sobrevive à listagem)
|  4. FLOOR incondicional — listada >= LIMBO_MONTHS_THRESHOLD (limbo)
|  5. FLOOR incondicional — expira em <= 30 dias
|  6. Filtragem: pula vendidas, sem gamivo_id, sem acquired_at
|  7. Não altera quando o min_api já está correto; retorna IDs atualizados
|
| Sem chamadas à API Gamivo — apenas operações no banco.
|
*/

use App\UseCases\Marketplaces\Gamivo\RegulateMinApiUseCase;
use Illuminate\Support\Facades\DB;

// ── Helper ────────────────────────────────────────────────────────────────────

function insertRegulateKey(string $gamivoId = '440', array $overrides = []): int
{
    return DB::table('keys')->insertGetId(array_merge([
        'game_name' => 'Regulate Game',
        'gamivo_id' => $gamivoId,
        'key_code' => 'REGULATE-'.uniqid(),
        'market_price' => 5.00,
        'individual_cost' => 2.00,
        'min_api' => 5.00,
        'max_api' => 20.00,
        'purchase_profit_percent' => 25.00,
        'supplier_url' => 'https://steamcommunity.com/id/test',
        'supplier_id' => 1,
        'claim_type' => 'Nenhuma',
        'key_format' => 'RK',
        'sell_platform' => 'Gamivo',
        'acquired_at' => now()->subMonths(1)->toDateString(),
        'listed_at' => null,
        'expires_at' => null,
        'sold_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

describe('RegulateMinApiUseCase', function () {

    beforeEach(function () {
        DB::table('suppliers')->insert(['id' => 1, 'url' => 'https://steamcommunity.com/id/seed']);
    });

    // ── Não listada — decaimento por acquired_at ─────────────────────────────

    it('applies the aging margin for an unlisted key acquired >= UNLISTED_AGING_MONTHS ago (15%)', function () {
        insertRegulateKey('440', ['individual_cost' => 2.00, 'acquired_at' => now()->subMonths(7)->toDateString()]);

        app(RegulateMinApiUseCase::class)->execute();

        expect((float) DB::table('keys')->where('gamivo_id', '440')->value('min_api'))->toBe(2.30);
    });

    it('applies the moderate margin for an unlisted key acquired >= UNLISTED_MODERATE_MONTHS and below the aging tier (40%)', function () {
        insertRegulateKey('440', ['individual_cost' => 2.00, 'acquired_at' => now()->subMonths(5)->toDateString()]);

        app(RegulateMinApiUseCase::class)->execute();

        expect((float) DB::table('keys')->where('gamivo_id', '440')->value('min_api'))->toBe(2.80);
    });

    it('applies the default cost tier for an unlisted key acquired less than UNLISTED_MODERATE_MONTHS ago (60%)', function () {
        insertRegulateKey('440', ['individual_cost' => 2.00, 'acquired_at' => now()->subMonths(1)->toDateString()]);

        app(RegulateMinApiUseCase::class)->execute();

        expect((float) DB::table('keys')->where('gamivo_id', '440')->value('min_api'))->toBe(3.20);
    });

    // ── Listada — decaimento por listed_at ────────────────────────────────────

    it('applies the listed-aging margin for a key listed >= LISTED_AGING_MONTHS ago (20%)', function () {
        // acquired_at igual ao listed_at, recente o bastante pra não bater o floor de estoque antigo
        insertRegulateKey('440', [
            'individual_cost' => 2.00,
            'acquired_at' => now()->subMonths(7)->toDateString(),
            'listed_at' => now()->subMonths(7)->toDateString(),
        ]);

        app(RegulateMinApiUseCase::class)->execute();

        expect((float) DB::table('keys')->where('gamivo_id', '440')->value('min_api'))->toBe(2.40);
    });

    it('applies the listed-moderate margin for a key listed >= LISTED_MODERATE_MONTHS and below the aging tier (30%)', function () {
        insertRegulateKey('440', [
            'individual_cost' => 2.00,
            'acquired_at' => now()->subMonths(5)->toDateString(),
            'listed_at' => now()->subMonths(5)->toDateString(),
        ]);

        app(RegulateMinApiUseCase::class)->execute();

        expect((float) DB::table('keys')->where('gamivo_id', '440')->value('min_api'))->toBe(2.60);
    });

    it('applies the listed-early margin for a key listed >= LISTED_EARLY_MONTHS and below the moderate tier (40%)', function () {
        insertRegulateKey('440', [
            'individual_cost' => 2.00,
            'acquired_at' => now()->subMonths(3)->subDays(15)->toDateString(),
            'listed_at' => now()->subMonths(3)->subDays(15)->toDateString(),
        ]);

        app(RegulateMinApiUseCase::class)->execute();

        expect((float) DB::table('keys')->where('gamivo_id', '440')->value('min_api'))->toBe(2.80);
    });

    it('applies the default cost tier for a key listed less than LISTED_EARLY_MONTHS ago (60%)', function () {
        insertRegulateKey('440', [
            'individual_cost' => 2.00,
            'acquired_at' => now()->subMonth()->toDateString(),
            'listed_at' => now()->subMonth()->toDateString(),
        ]);

        app(RegulateMinApiUseCase::class)->execute();

        expect((float) DB::table('keys')->where('gamivo_id', '440')->value('min_api'))->toBe(3.20);
    });

    // ── FLOORs incondicionais ─────────────────────────────────────────────────

    it('applies FLOOR for a key acquired >= OLD_KEY_MONTHS ago, unlisted', function () {
        insertRegulateKey('440', [
            'individual_cost' => 20.00,
            'acquired_at' => now()->subMonths(11)->toDateString(),
        ]);

        app(RegulateMinApiUseCase::class)->execute();

        expect((float) DB::table('keys')->where('gamivo_id', '440')->value('min_api'))->toBe(0.02);
    });

    it('keeps the old-stock FLOOR even after the key is listed', function () {
        // acquired_at >= OLD_KEY_MONTHS permanece verdadeiro para sempre — mesmo listada
        // recentemente, não deve "recuperar" uma margem normal
        insertRegulateKey('440', [
            'individual_cost' => 20.00,
            'acquired_at' => now()->subMonths(13)->toDateString(),
            'listed_at' => now()->subMonth()->toDateString(),
        ]);

        app(RegulateMinApiUseCase::class)->execute();

        expect((float) DB::table('keys')->where('gamivo_id', '440')->value('min_api'))->toBe(0.02);
    });

    it('applies FLOOR for a key listed >= LIMBO_MONTHS_THRESHOLD ago (limbo), regardless of cost', function () {
        insertRegulateKey('440', [
            'individual_cost' => 20.00,
            'acquired_at' => now()->subMonths(20)->toDateString(),
            'listed_at' => now()->subMonths(11)->toDateString(),
        ]);

        app(RegulateMinApiUseCase::class)->execute();

        expect((float) DB::table('keys')->where('gamivo_id', '440')->value('min_api'))->toBe(0.02);
    });

    it('applies FLOOR for a key expiring within 30 days, regardless of listed status', function () {
        insertRegulateKey('440', [
            'individual_cost' => 20.00,
            'acquired_at' => now()->subMonth()->toDateString(),
            'expires_at' => now()->addDays(10)->toDateString(),
        ]);

        app(RegulateMinApiUseCase::class)->execute();

        expect((float) DB::table('keys')->where('gamivo_id', '440')->value('min_api'))->toBe(0.02);
    });

    // ── Filtragem ──────────────────────────────────────────────────────────────

    it('skips keys that are already sold', function () {
        insertRegulateKey('440', [
            'individual_cost' => 2.00,
            'acquired_at' => now()->subMonths(7)->toDateString(),
            'min_api' => 5.00,
            'sold_at' => now()->subDays(2)->toDateString(),
        ]);

        $result = app(RegulateMinApiUseCase::class)->execute();

        expect($result)->toBeEmpty()
            ->and((float) DB::table('keys')->where('gamivo_id', '440')->value('min_api'))->toBe(5.00);
    });

    it('skips keys without gamivo_id', function () {
        insertRegulateKey(overrides: [
            'individual_cost' => 2.00,
            'acquired_at' => now()->subMonths(7)->toDateString(),
            'min_api' => 5.00,
            'gamivo_id' => null,
        ]);

        $result = app(RegulateMinApiUseCase::class)->execute();

        expect($result)->toBeEmpty();
    });

    it('skips keys with null acquired_at', function () {
        insertRegulateKey('440', ['individual_cost' => 2.00, 'min_api' => 5.00, 'acquired_at' => null]);

        $result = app(RegulateMinApiUseCase::class)->execute();

        expect($result)->toBeEmpty();
    });

    // ── Retorno e idempotência ────────────────────────────────────────────────

    it('returns the IDs of updated keys', function () {
        $id = insertRegulateKey('440', [
            'individual_cost' => 2.00,
            'acquired_at' => now()->subMonths(7)->toDateString(),
            'min_api' => 5.00,
        ]);

        $result = app(RegulateMinApiUseCase::class)->execute();

        expect($result)->toContain($id);
    });

    it('does not report a key as updated when min_api is already correct', function () {
        insertRegulateKey('440', [
            'individual_cost' => 2.00,
            'acquired_at' => now()->subMonths(7)->toDateString(),
            'min_api' => 2.30,
        ]);

        $result = app(RegulateMinApiUseCase::class)->execute();

        expect($result)->toBeEmpty();
    });

    it('updates all eligible keys in a single run, without a batch limit', function () {
        for ($i = 1; $i <= 15; $i++) {
            insertRegulateKey((string) (1000 + $i), [
                'individual_cost' => 2.00,
                'acquired_at' => now()->subMonths(7)->toDateString(),
                'min_api' => 5.00,
            ]);
        }

        $result = app(RegulateMinApiUseCase::class)->execute();

        expect($result)->toHaveCount(15);
    });
});
