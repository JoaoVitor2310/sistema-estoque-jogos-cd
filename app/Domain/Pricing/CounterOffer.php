<?php

namespace App\Domain\Pricing;

/**
 * Os valores de uma contraproposta, jogo a jogo, a partir do total que o
 * supplier pediu.
 *
 * Cada jogo chega com o valor vigente (o que o supplier leu) e o **alvo** da
 * rodada — o valor no lucro-alvo dela, já limitado à oferta máxima; `null`
 * quando não há como recalcular. O **limite** do jogo nesta rodada é o alvo,
 * mas **nunca abaixo do vigente** ([[self::limits]]): se a cotação mudou e a
 * máxima caiu, o jogo mantém o valor que o supplier já leu. Tudo em centavos
 * inteiros da moeda da oferta, para o total bater com o que ele lê.
 *
 * | Pedido                  | Oferta                                           |
 * |-------------------------|--------------------------------------------------|
 * | cabe na soma dos limites | exatamente o pedido, distribuído (abaixo)       |
 * | passa da soma            | cada jogo no seu limite: o máximo desta rodada  |
 *
 * **Distribuição:** cada jogo sobe na mesma proporção em relação ao vigente;
 * o que chega ao limite para nele, e o que faltaria a ele é repartido entre os
 * outros, na mesma proporção. A ordem relativa dos jogos se mantém.
 */
final class CounterOffer
{
    /**
     * Se a rodada ainda pode subir algum jogo. Sem isso não há contraproposta:
     * a vigente já é o melhor que o Estoque oferta.
     *
     * @param  list<array{current: int, target: int|null}>  $lines  centavos
     */
    public static function canConcede(array $lines): bool
    {
        foreach (self::limits($lines) as $i => $limit) {
            if ($limit > $lines[$i]['current']) {
                return true;
            }
        }

        return false;
    }

    /**
     * O limite de cada jogo nesta rodada: o alvo, nunca abaixo do vigente.
     *
     * @param  list<array{current: int, target: int|null}>  $lines  centavos
     * @return list<int>
     */
    public static function limits(array $lines): array
    {
        return array_map(fn (array $line) => max($line['current'], $line['target'] ?? $line['current']), $lines);
    }

    /**
     * Os novos valores, em centavos, na ordem das linhas recebidas.
     *
     * @param  list<array{current: int, target: int|null}>  $lines  centavos
     * @param  int  $requested  centavos; acima da soma dos vigentes
     * @return list<int>
     *
     * @throws \InvalidArgumentException para pedido que não passa da soma dos
     *                                   vigentes: distribuí-lo baixaria jogos
     *                                   abaixo do que o supplier já leu
     */
    public static function prices(array $lines, int $requested): array
    {
        $limits = self::limits($lines);
        $currents = array_column($lines, 'current');

        if ($requested <= array_sum($currents)) {
            throw new \InvalidArgumentException("A counter offer must be above the current total ({$requested} <= ".array_sum($currents).').');
        }

        // Pedido que alcança a soma dos limites leva todos os limites.
        if ($requested >= array_sum($limits)) {
            return $limits;
        }

        // Proporção comum k sobre o vigente; quem passaria do limite para nele.
        $fixed = [];
        do {
            $free = array_diff_key($currents, $fixed);
            $factor = ($requested - array_sum($fixed)) / array_sum($free);
            $capped = false;

            foreach ($free as $i => $current) {
                if ($current * $factor >= $limits[$i]) {
                    $fixed[$i] = $limits[$i];
                    $capped = true;
                }
            }
        } while ($capped && count($fixed) < count($lines));

        $exact = [];
        foreach ($currents as $i => $current) {
            $exact[$i] = $fixed[$i] ?? $current * $factor;
        }

        return self::toCents($exact, $limits, $requested);
    }

    /**
     * Arredonda para baixo e reparte os centavos que faltam para o total bater,
     * a começar pelos jogos com maior fração perdida — sem passar de limite.
     *
     * @param  array<int, float|int>  $exact
     * @param  array<int, int>  $limits
     * @return list<int>
     */
    private static function toCents(array $exact, array $limits, int $requested): array
    {
        $cents = array_map(fn ($value) => (int) floor($value + 1e-9), $exact);
        $missing = $requested - array_sum($cents);

        $fractions = [];
        foreach ($exact as $i => $value) {
            $fractions[$i] = $value - $cents[$i];
        }
        arsort($fractions);

        while ($missing > 0) {
            $given = false;
            foreach (array_keys($fractions) as $i) {
                if ($missing > 0 && $cents[$i] < $limits[$i]) {
                    $cents[$i]++;
                    $missing--;
                    $given = true;
                }
            }

            if (! $given) {
                break;
            }
        }

        return array_values($cents);
    }
}
