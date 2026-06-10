<?php

/*
|--------------------------------------------------------------------------
| SupplierEvaluationTest — POST /api/suppliers/evaluate
|--------------------------------------------------------------------------
|
| Cobre o endpoint chamado pelo Price Researcher para saber quais jogos
| de um fornecedor são rentáveis e com qual valor em keys TF2.
|
| Casos testados:
|
|   1. Sem Bearer token          → 401
|   2. Token errado              → 401
|   3. Body vazio                → 422
|   4. Campo price_euro ausente  → 422
|   5. Todos rentáveis           → 200 com tf2_price calculado
|   6. Nenhum rentável           → 200 com profitable vazio
|   7. Mix rentável/não rentável → apenas os rentáveis retornados
|   8. Preço abaixo do micro-threshold (€0.28) → income negativo, descartado
|   9. tf2_price = 0 (asset ausente) → todos descartados
|  10. Campos passados intactos na resposta (name, price_euro, popularity, region)
|
*/

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

const EVAL_SECRET = 'test-supplier-secret';

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Semeia as taxas do Gamivo e o preço TF2 necessários para o serviço funcionar. */
function seedEvaluationDeps(float $tf2Price = 0.95): void
{
    Cache::flush();

    DB::table('fees')->insertOrIgnore([
        ['name' => 'gamivo_percent_low', 'preco' => 0.06, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_low', 'preco' => 0.25, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_percent_high', 'preco' => 0.08, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_high', 'preco' => 0.40, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('assets')->insertOrIgnore([
        'name' => 'TF2',
        'price_euro' => $tf2Price,
        'price_dollar' => 0.00,
        'price_brl' => 0.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ── Autenticação ──────────────────────────────────────────────────────────────

describe('POST /api/suppliers/evaluate — authentication', function () {

    beforeEach(fn () => Config::set('services.external_secret', EVAL_SECRET));

    it('returns 401 with no bearer token', function () {
        $this->postJson('/api/suppliers/evaluate', ['games' => []])
            ->assertStatus(401);
    });

    it('returns 401 with wrong bearer token', function () {
        $this->withToken('wrong-secret')
            ->postJson('/api/suppliers/evaluate', ['games' => []])
            ->assertStatus(401);
    });
});

// ── Validação ─────────────────────────────────────────────────────────────────

describe('POST /api/suppliers/evaluate — validation', function () {

    beforeEach(fn () => Config::set('services.external_secret', EVAL_SECRET));

    it('returns 422 when games is missing', function () {
        $this->withToken(EVAL_SECRET)
            ->postJson('/api/suppliers/evaluate', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['games']);
    });

    it('returns 422 when games is empty array', function () {
        $this->withToken(EVAL_SECRET)
            ->postJson('/api/suppliers/evaluate', ['games' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['games']);
    });

    it('returns 422 when price_euro is missing', function () {
        $this->withToken(EVAL_SECRET)
            ->postJson('/api/suppliers/evaluate', [
                'games' => [[
                    'name' => 'Half-Life',
                    'popularity' => 500,
                    'region' => 'global',
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['games.0.price_euro']);
    });
});

// ── Cálculo de rentabilidade ──────────────────────────────────────────────────

describe('POST /api/suppliers/evaluate — profitability calculation', function () {

    beforeEach(function () {
        Config::set('services.external_secret', EVAL_SECRET);
        seedEvaluationDeps(tf2Price: 0.95);
    });

    it('returns all games when all are profitable', function () {
        // price_euro = 4.50 → netIncome = 4.50 × 0.94 − 0.25 = 3.98
        // tf2_offer = 3.98 / 2 / 0.95 ≈ 2.09
        $response = $this->withToken(EVAL_SECRET)
            ->postJson('/api/suppliers/evaluate', [
                'games' => [
                    ['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => 'global'],
                    ['name' => 'Terraformers', 'price_euro' => 3.00, 'popularity' => 155, 'region' => 'eu'],
                ],
            ])
            ->assertStatus(200)
            ->assertJsonStructure(['profitable' => [['name', 'price_euro', 'popularity', 'region', 'tf2_price']]]);

        expect($response->json('profitable'))->toHaveCount(2);
    });

    it('returns empty array when no game is profitable', function () {
        // price_euro = 0.10 → netIncome = 0.10 − 0.11 = −0.01 → tf2Offer < 0 → descartado
        $response = $this->withToken(EVAL_SECRET)
            ->postJson('/api/suppliers/evaluate', [
                'games' => [
                    ['name' => 'Junk Game', 'price_euro' => 0.10, 'popularity' => 5, 'region' => 'global'],
                ],
            ])
            ->assertStatus(200);

        expect($response->json('profitable'))->toBeEmpty();
    });

    it('returns only profitable games from a mixed list', function () {
        $response = $this->withToken(EVAL_SECRET)
            ->postJson('/api/suppliers/evaluate', [
                'games' => [
                    ['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => 'global'],
                    ['name' => 'Junk Game', 'price_euro' => 0.10, 'popularity' => 5, 'region' => 'global'],
                ],
            ])
            ->assertStatus(200);

        $profitable = $response->json('profitable');

        expect($profitable)->toHaveCount(1);
        expect($profitable[0]['name'])->toBe('Half-Life');
    });

    it('discards games below the micro-threshold (price too low to cover fixed fee)', function () {
        // price_euro = 0.20 < 0.28 → netIncome = 0.20 − 0.11 = 0.09
        // tf2_offer = 0.09 / 2 / 0.95 ≈ 0.047 → rentável (> 0)
        // price_euro = 0.05 → netIncome = 0.05 − 0.11 = −0.06 → descartado
        $response = $this->withToken(EVAL_SECRET)
            ->postJson('/api/suppliers/evaluate', [
                'games' => [
                    ['name' => 'Cheap Game', 'price_euro' => 0.20, 'popularity' => 10, 'region' => 'global'],
                    ['name' => 'Too Cheap', 'price_euro' => 0.05, 'popularity' => 2, 'region' => 'global'],
                ],
            ])
            ->assertStatus(200);

        $profitable = $response->json('profitable');

        expect($profitable)->toHaveCount(1);
        expect($profitable[0]['name'])->toBe('Cheap Game');
    });

    it('discards all games when tf2 asset price is zero', function () {
        Cache::flush();
        DB::table('assets')->where('name', 'TF2')->update(['price_euro' => 0]);

        $this->withToken(EVAL_SECRET)
            ->postJson('/api/suppliers/evaluate', [
                'games' => [
                    ['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => 'global'],
                ],
            ])
            ->assertStatus(200)
            ->assertJson(['profitable' => []]);
    });
});

// ── Forma da resposta ─────────────────────────────────────────────────────────

describe('POST /api/suppliers/evaluate — response shape', function () {

    beforeEach(function () {
        Config::set('services.external_secret', EVAL_SECRET);
        seedEvaluationDeps(tf2Price: 0.95);
    });

    it('echoes back name, price_euro, popularity and region unchanged', function () {
        $response = $this->withToken(EVAL_SECRET)
            ->postJson('/api/suppliers/evaluate', [
                'games' => [
                    ['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => 'eu'],
                ],
            ])
            ->assertStatus(200);

        $item = $response->json('profitable.0');

        expect($item['name'])->toBe('Half-Life');
        expect($item['price_euro'])->toBe(4.50);
        expect($item['popularity'])->toBe(500);
        expect($item['region'])->toBe('eu');
    });

    it('returns tf2_price rounded to 2 decimal places', function () {
        $response = $this->withToken(EVAL_SECRET)
            ->postJson('/api/suppliers/evaluate', [
                'games' => [
                    ['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => 'global'],
                ],
            ])
            ->assertStatus(200);

        $tf2Price = $response->json('profitable.0.tf2_price');

        // Verifica que é um número com no máximo 2 casas decimais
        expect($tf2Price)->toBeFloat();
        expect(round($tf2Price, 2))->toBe($tf2Price);
    });

    it('calculates tf2_price correctly for a known input', function () {
        // price_euro = 4.50, fee_low = 6% + €0.25, tf2 = 0.95, tier = 100%
        // netIncome = 4.50 × (1 − 0.06) − 0.25 = 4.23 − 0.25 = 3.98
        // tf2Offer  = 3.98 / (1 + 1.0) / 0.95 = 3.98 / 2 / 0.95 ≈ 2.09
        $response = $this->withToken(EVAL_SECRET)
            ->postJson('/api/suppliers/evaluate', [
                'games' => [
                    ['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => 'global'],
                ],
            ])
            ->assertStatus(200);

        $tf2Price = $response->json('profitable.0.tf2_price');

        expect($tf2Price)->toBe(round(3.98 / 2.0 / 0.95, 2));
    });
});
