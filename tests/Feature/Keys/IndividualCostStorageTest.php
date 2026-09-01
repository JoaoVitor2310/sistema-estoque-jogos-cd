<?php

/*
|--------------------------------------------------------------------------
| Como a coluna individual_cost é guardada
|--------------------------------------------------------------------------
|
| Arquivo temático por exceção deliberada (ver docs/agents/testing.md): o
| assunto é a coluna, não uma unidade — quem a protege são o mutator do modelo
| e a migration que limpou a base, e as duas asserções só fazem sentido juntas.
| A regra de negócio em si (ProfitCalculator::normalizeCost) é testada no
| ProfitCalculatorTest, com a unidade que a implementa.
|
| O cálculo saneado não basta sozinho: enquanto o valor inválido puder ser
| gravado, ele reaparece em relatório e export lendo a coluna direto.
|
*/

use App\Models\Key;
use Illuminate\Support\Facades\DB;

function insertKeyWithCost(string $keyCode, float $cost): int
{
    DB::table('suppliers')->insertOrIgnore(['id' => 77, 'url' => 'https://steamcommunity.com/id/cost']);

    return (int) DB::table('keys')->insertGetId([
        'game_name' => 'Cost Guard Game',
        'gamivo_id' => '900001',
        'key_code' => $keyCode,
        'market_price' => 1.00,
        'individual_cost' => $cost,
        'simulated_income' => 0.13,
        'min_api' => 0.10,
        'max_api' => 1.00,
        'purchase_profit_percent' => 0,
        'supplier_url' => 'https://steamcommunity.com/id/cost',
        'supplier_id' => 77,
        'claim_type' => 'Nenhuma',
        'key_format' => 'RK',
        'sell_platform' => 'Gamivo',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('individual_cost storage', function () {

    it('clamps a negative cost to zero when saving through the model', function () {
        $key = Key::create([
            'game_name' => 'Clamp Game',
            'gamivo_id' => '900002',
            'key_code' => 'CLAMP-KEY-001',
            'market_price' => 1.00,
            'individual_cost' => -0.01,
            'min_api' => 0.10,
            'max_api' => 1.00,
            'purchase_profit_percent' => 0,
            'supplier_url' => 'https://steamcommunity.com/id/cost',
            'claim_type' => 'Nenhuma',
            'key_format' => 'RK',
            'sell_platform' => 'Gamivo',
        ]);

        expect((float) $key->fresh()->individual_cost)->toBe(0.0);
    });

    it('keeps a positive cost as given', function () {
        $id = insertKeyWithCost('CLAMP-KEY-002', 2.50);

        Key::find($id)->update(['individual_cost' => 1.75]);

        expect((float) Key::find($id)->individual_cost)->toEqualWithDelta(1.75, 0.001);
    });

    it('clears the negative costs already in the database', function () {
        // A gravação passa por baixo do modelo, como aconteceu na importação antiga
        $id = insertKeyWithCost('CLAMP-KEY-003', -0.01);
        DB::table('keys')->where('id', $id)->update(['sold_price' => 0.20]);

        // A migration já rodou no setup da suíte: invocar a classe é o que exercita o up()
        $migration = require base_path('database/migrations/2026_09_01_000001_clamp_negative_individual_costs.php');
        $migration->up();

        $row = DB::table('keys')->find($id);

        // margem deixa de ser -2100% e passa a refletir a venda lucrativa
        expect((float) $row->individual_cost)->toBe(0.0)
            ->and((float) $row->sale_profit)->toEqualWithDelta(0.20, 0.001)
            ->and((float) $row->sale_profit_percent)->toEqualWithDelta(2000.0, 0.01);
    });
});
