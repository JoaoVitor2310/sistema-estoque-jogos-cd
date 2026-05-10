<?php

/*
|--------------------------------------------------------------------------
| ReduceAgingKeysMinPriceUseCase — feature tests
|--------------------------------------------------------------------------
|
| Cobre a redução automática do piso de preço (min_api) para keys paradas
| no estoque há >= 7 meses (AGING_KEY_MONTHS), facilitando a listagem
| pelo AutoSellUseCase em mercados que caíram desde a aquisição.
|
| Regras verificadas:
|  1. min_api é reduzido para individual_cost × 1.2 quando >= 7 meses
|  2. min_api já no limiar ou abaixo não é alterado (nunca aumenta)
|  3. Keys com < 7 meses não são tocadas
|  4. Keys já listadas (listed_at não nulo) são ignoradas
|  5. Retorna os IDs das keys atualizadas
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

    // ── Happy path ────────────────────────────────────────────────────────────

    it('reduces min_api to individual_cost × 1.2 for keys acquired >= 7 months ago', function () {
        // custo = 2.00 → novo min_api = 2.40; min_api atual = 5.00 → deve reduzir
        insertAgingKey('440', ['individual_cost' => 2.00, 'min_api' => 5.00]);

        app(ReduceAgingKeysMinPriceUseCase::class)->execute();

        expect((float) DB::table('keys')->where('gamivo_id', '440')->value('min_api'))
            ->toBe(2.40);
    });

    it('returns the IDs of updated keys', function () {
        $id = insertAgingKey('440', ['individual_cost' => 2.00, 'min_api' => 5.00]);

        $result = app(ReduceAgingKeysMinPriceUseCase::class)->execute();

        expect($result)->toContain($id);
    });

    it('updates multiple keys in a single run', function () {
        $id1 = insertAgingKey('440', ['individual_cost' => 2.00, 'min_api' => 5.00]);
        $id2 = insertAgingKey('730', ['individual_cost' => 3.00, 'min_api' => 8.00]);

        $result = app(ReduceAgingKeysMinPriceUseCase::class)->execute();

        expect($result)->toHaveCount(2)->toContain($id1)->toContain($id2);
        expect((float) DB::table('keys')->where('gamivo_id', '440')->value('min_api'))->toBe(2.40);
        expect((float) DB::table('keys')->where('gamivo_id', '730')->value('min_api'))->toBe(3.60);
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

    it('skips keys acquired less than 7 months ago', function () {
        insertAgingKey('440', [
            'individual_cost' => 2.00,
            'min_api' => 5.00,
            'acquired_at' => now()->subMonths(6)->toDateString(),
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
