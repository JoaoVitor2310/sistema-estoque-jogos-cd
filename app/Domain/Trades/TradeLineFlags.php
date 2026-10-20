<?php

namespace App\Domain\Trades;

/**
 * As marcas que o servidor grava numa linha de trade sobre o jogo dela — e o
 * que elas fazem com a oferta.
 *
 * Cada marca é um aviso sobre o jogo no momento em que a linha nasceu ou
 * trocou de jogo, e todas seguem o mesmo desenho: o jogo marcado **não é
 * ofertado** ao supplier, mas continua virando linha da trade, para a equipe
 * ver o que ficou de fora. Quem decide cada marca é a sua política:
 *
 * | Coluna            | Política                                     |
 * |-------------------|----------------------------------------------|
 * | `is_overstocked`  | [[OverstockPolicy]] (docs/adr/0013)          |
 * | `is_giveaway`     | [[App\Domain\Games\GiveawayPolicy]] (docs/adr/0014) |
 *
 * Uma marca nova entra em [[self::COLUMNS]] e passa a valer em todo caminho
 * que monta oferta ou linha.
 */
final class TradeLineFlags
{
    /** O jogo estava encalhado no estoque, na região da linha. */
    public const OVERSTOCKED = 'is_overstocked';

    /** O jogo estava em giveaway (ativo ou saído há pouco). */
    public const GIVEAWAY = 'is_giveaway';

    public const COLUMNS = [self::OVERSTOCKED, self::GIVEAWAY];

    /**
     * As marcas de um jogo já avaliado, como atributos de `trade_lines`.
     *
     * Marca ausente vale `false`: jogo que não passou pela avaliação nasce sem aviso.
     *
     * @param  array<string, mixed>  $game
     * @return array<string, bool>
     */
    public static function of(array $game): array
    {
        $flags = [];

        foreach (self::COLUMNS as $column) {
            $flags[$column] = (bool) ($game[$column] ?? false);
        }

        return $flags;
    }

    /**
     * O jogo pode ser ofertado ao supplier? Só se nenhuma marca valer.
     *
     * @param  array<string, mixed>  $game
     */
    public static function isOfferable(array $game): bool
    {
        return ! in_array(true, self::of($game), true);
    }
}
