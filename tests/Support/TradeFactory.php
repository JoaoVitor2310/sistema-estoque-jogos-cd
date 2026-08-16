<?php

namespace Tests\Support;

use App\Models\Trade;
use Illuminate\Support\Facades\DB;

/**
 * Seeds de trade com linhas.
 *
 * Namespaced pelo mesmo motivo de [[FinancialMonthFactory]]: helper solto no
 * topo de um arquivo de teste é promovido ao namespace global pelo Pest, e
 * `makeTrade` já existe com esse nome em mais de um arquivo.
 *
 * Insere por query builder, não por model, porque vários testes precisam fixar
 * `created_at`/`last_commented_at` no passado — e esses campos não são
 * fillable.
 */
final class TradeFactory
{
    /**
     * @param  array<int, string|array<string, mixed>>  $lines  nome do jogo, ou atributos da linha
     * @param  array<string, mixed>  $attrs  colunas da própria trade
     */
    public static function withLines(array $lines = [], array $attrs = []): Trade
    {
        $now = now();

        $tradeId = DB::table('trades')->insertGetId(array_merge([
            'created_at' => $now,
            'updated_at' => $now,
        ], $attrs));

        foreach (array_values($lines) as $position => $line) {
            $attributes = is_array($line) ? $line : ['game_name' => $line];

            DB::table('trade_lines')->insert($attributes + [
                'trade_id' => $tradeId,
                'position' => $position,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return Trade::findOrFail($tradeId);
    }
}
