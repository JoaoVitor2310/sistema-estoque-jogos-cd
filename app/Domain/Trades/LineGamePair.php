<?php

namespace App\Domain\Trades;

/**
 * O jogo de uma linha de trade é o par (`game_name`, `region`).
 *
 * Tudo que é derivado do jogo da linha — o `gamivo_id` ([[GamivoIdentity]]) e
 * a marca de encalhe ([[OverstockPolicy]]) — deixa de valer quando o par muda,
 * e as duas regras precisam concordar sobre o que é "mudar". Por isso a
 * pergunta mora aqui, e não dentro de uma delas.
 */
final class LineGamePair
{
    /** As colunas que formam o jogo da linha. */
    public const COLUMNS = ['game_name', 'region'];

    /**
     * A escrita troca o jogo da linha — o nome ou a região?
     *
     * `$patch` é parcial: coluna ausente é coluna inalterada.
     *
     * @param  array<string, mixed>  $stored  valores atuais da linha
     * @param  array<string, mixed>  $patch  colunas que a escrita traz
     */
    public static function changedBy(array $stored, array $patch): bool
    {
        foreach (self::COLUMNS as $column) {
            if (array_key_exists($column, $patch)
                && ! self::same($stored[$column] ?? null, $patch[$column])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Igualdade como o lookup do id a enxerga: `GameService::getIdGamivo` casa o
     * nome por `LOWER(...)`. Retocar a caixa ou um espaço sobrando não é trocar
     * de jogo, e refazer o que deriva dele a cada retoque desses seria ruído.
     */
    public static function same(mixed $before, mixed $after): bool
    {
        return self::canonical($before) === self::canonical($after);
    }

    private static function canonical(mixed $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', (string) $value) ?? '';

        return mb_strtolower(trim($collapsed));
    }
}
