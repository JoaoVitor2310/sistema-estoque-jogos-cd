<?php

/*
|--------------------------------------------------------------------------
| ReduceAgingKeysMinPriceUseCase — feature tests
|--------------------------------------------------------------------------
|
| Cobre a redução automática do piso de preço (min_api) para keys paradas
| no estoque, em dois tiers de idade:
|
|  Tier moderado  (>= 4 meses e < 7 meses): min_api = custo × 1.5 (margem 50%)
|  Tier aging     (>= 7 meses e < 10 meses): min_api = custo × 1.2 (margem 20%)
|
| Regras verificadas:
|  1. min_api é reduzido para individual_cost × 1.2 quando >= 7 meses
|  2. min_api é reduzido para individual_cost × 1.5 quando >= 4 e < 7 meses
|  3. min_api já no limiar ou abaixo não é alterado (nunca aumenta)
|  4. Keys com < 4 meses não são tocadas
|  5. Keys com >= 10 meses não são tocadas (responsabilidade do age override)
|  6. Keys já listadas (listed_at não nulo) são ignoradas
|  7. Retorna os IDs das keys atualizadas
|
| Sem chamadas à API Gamivo — apenas operações no banco.
|
*/

use App\UseCases\Marketplaces\Gamivo\ReduceAgingKeysMinPriceUseCase;
use Illuminate\Support\Facades\DB;

// ── Helper ────────────────────────────────────────────────────────────────────

/**
 * Insere uma key candidata à redução de min_api e retorna o ID gerado.
 */
function insertAgingKey(string $gamivoId = '440', array $overrides = []): int
{
    return DB::table('keys')->insertGetId(array_merge([
        'game_name' => 'Aging Game',
        'gamivo_id' => $gamivoId,
        'key_code' => 'AGING-'.uniqid(),
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
        'acquired_at' => now()->subMonths(8)->toDateString(),
        'listed_at' => null,
        'sold_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('ReduceAgingKeysMinPriceUseCase', function () {

    beforeEach(function () {
        DB::table('suppliers')->insert(['id' => 1, 'supplier_url' => 'https://steamcommunity.com/id/seed']);
    });

    // ── Tier aging: >= 7 meses → multiplier 1.2 ──────────────────────────────

    it('reduces min_api to individual_cost × 1.2 for keys acquired >= 7 months ago', function () {
        // custo = 2.00 → novo min_api = 2.40; min_api atual = 5.00 → deve reduzir
        insertAgingKey('440', ['individual_cost' => 2.00, 'min_api' => 5.00]);

        app(ReduceAgingKeysMinPriceUseCase::class)->execute();

        expect((float) DB::table('keys')->where('gamivo_id', '440')->value('min_api'))
            ->toBe(2.40);
    });

    // ── Tier moderado: >= 4 meses e < 7 meses → multiplier 1.5 ───────────────

    it('reduces min_api to individual_cost × 1.5 for keys acquired >= 4 months and < 7 months ago', function () {
        // custo = 2.00 → novo min_api = 3.00; min_api atual = 5.00 → deve reduzir
        insertAgingKey('440', [
            'individual_cost' => 2.00,
            'min_api' => 5.00,
            'acquired_at' => now()->subMonths(5)->toDateString(),
        ]);

        app(ReduceAgingKeysMinPriceUseCase::class)->execute();

        expect((float) DB::table('keys')->where('gamivo_id', '440')->value('min_api'))
            ->toBe(3.00);
    });

    it('applies the correct multiplier per tier in a single run', function () {
        // key de 8 meses → 1.2×; key de 5 meses → 1.5×
        $id1 = insertAgingKey('440', [
            'individual_cost' => 2.00, 'min_api' => 5.00,
            'acquired_at' => now()->subMonths(8)->toDateString(),
        ]);
        $id2 = insertAgingKey('730', [
            'individual_cost' => 2.00, 'min_api' => 5.00,
            'acquired_at' => now()->subMonths(5)->toDateString(),
        ]);

        $result = app(ReduceAgingKeysMinPriceUseCase::class)->execute();

        expect($result)->toHaveCount(2)->toContain($id1)->toContain($id2);
        expect((float) DB::table('keys')->where('gamivo_id', '440')->value('min_api'))->toBe(2.40);
        expect((float) DB::table('keys')->where('gamivo_id', '730')->value('min_api'))->toBe(3.00);
    });

    // ── Retorno e idempotência ────────────────────────────────────────────────

    it('returns the IDs of updated keys', function () {
        $id = insertAgingKey('440', ['individual_cost' => 2.00, 'min_api' => 5.00]);

        $result = app(ReduceAgingKeysMinPriceUseCase::class)->execute();

        expect($result)->toContain($id);
    });

    // ── Não reduz quando já está no limiar ────────────────────────────────────

    it('does not reduce min_api when it is already at the threshold', function () {
        // custo = 2.00 → limiar = 2.40; min_api = 2.40 → já no limiar, não altera
        insertAgingKey('440', ['individual_cost' => 2.00, 'min_api' => 2.40]);

        $result = app(ReduceAgingKeysMinPriceUseCase::class)->execute();

        expect($result)->toBeEmpty()
            ->and((float) DB::table('keys')->where('gamivo_id', '440')->value('min_api'))
            ->toBe(2.40);
    });

    it('does not reduce min_api when it is already below the threshold', function () {
        // custo = 2.00 → limiar = 2.40; min_api = 2.00 → abaixo, não altera
        insertAgingKey('440', ['individual_cost' => 2.00, 'min_api' => 2.00]);

        $result = app(ReduceAgingKeysMinPriceUseCase::class)->execute();

        expect($result)->toBeEmpty()
            ->and((float) DB::table('keys')->where('gamivo_id', '440')->value('min_api'))
            ->toBe(2.00);
    });

    // ── Filtragem por idade ───────────────────────────────────────────────────

    it('skips keys acquired >= 10 months ago (handled exclusively by AutoSellUseCase age override)', function () {
        insertAgingKey('440', [
            'individual_cost' => 2.00,
            'min_api' => 5.00,
            'acquired_at' => now()->subMonths(11)->toDateString(),
        ]);

        $result = app(ReduceAgingKeysMinPriceUseCase::class)->execute();

        expect($result)->toBeEmpty()
            ->and((float) DB::table('keys')->where('gamivo_id', '440')->value('min_api'))
            ->toBe(5.00);
    });

    it('skips keys acquired less than 4 months ago', function () {
        insertAgingKey('440', [
            'individual_cost' => 2.00,
            'min_api' => 5.00,
            'acquired_at' => now()->subMonths(3)->toDateString(),
        ]);

        $result = app(ReduceAgingKeysMinPriceUseCase::class)->execute();

        expect($result)->toBeEmpty()
            ->and((float) DB::table('keys')->where('gamivo_id', '440')->value('min_api'))
            ->toBe(5.00);
    });

    it('skips keys with null acquired_at', function () {
        insertAgingKey('440', ['individual_cost' => 2.00, 'min_api' => 5.00, 'acquired_at' => null]);

        $result = app(ReduceAgingKeysMinPriceUseCase::class)->execute();

        expect($result)->toBeEmpty();
    });

    // ── Filtragem por status ──────────────────────────────────────────────────

    it('skips keys that are already listed', function () {
        insertAgingKey('440', [
            'individual_cost' => 2.00,
            'min_api' => 5.00,
            'listed_at' => now()->subDays(5)->toDateString(),
        ]);

        $result = app(ReduceAgingKeysMinPriceUseCase::class)->execute();

        expect($result)->toBeEmpty();
    });

    it('skips keys that are already sold', function () {
        insertAgingKey('440', [
            'individual_cost' => 2.00,
            'min_api' => 5.00,
            'sold_at' => now()->subDays(3)->toDateString(),
        ]);

        $result = app(ReduceAgingKeysMinPriceUseCase::class)->execute();

        expect($result)->toBeEmpty();
    });
});
