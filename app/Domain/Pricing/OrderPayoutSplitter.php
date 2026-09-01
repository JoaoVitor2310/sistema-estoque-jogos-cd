<?php

namespace App\Domain\Pricing;

/**
 * Rateia entre as keys de um pedido o valor líquido efetivamente recebido.
 *
 * A Gamivo devolve o histórico de vendas com uma linha por oferta vendida, mas
 * cobra a taxa de mediação uma única vez por pedido — descontá-la linha a linha
 * multiplicaria a taxa pelo número de ofertas do pedido.
 *
 * O arredondamento para centavos usa o método do maior resto: a soma dos valores
 * devolvidos é exatamente o líquido do pedido, sem centavos perdidos na divisão
 * (dividir €1,00 entre 3 keys devolve 0,34 / 0,33 / 0,33, não 0,33 três vezes).
 */
final class OrderPayoutSplitter
{
    /**
     * @param  array<string, float>  $grossByKey  key_code => bruto da oferta atribuído àquela key
     * @return array<string, float> key_code => líquido recebido; a soma é o líquido do pedido
     */
    public static function split(array $grossByKey): array
    {
        if ($grossByKey === []) {
            return [];
        }

        $netCents = (int) round((array_sum($grossByKey) - IncomeCalculator::MEDIATION_FEE) * 100);

        return self::allocateCents($grossByKey, $netCents);
    }

    /**
     * Distribui $totalCents proporcionalmente aos pesos, em centavos inteiros.
     *
     * @param  array<string, float>  $weights
     * @return array<string, float>
     */
    private static function allocateCents(array $weights, int $totalCents): array
    {
        $totalWeight = array_sum($weights);
        $count = count($weights);

        $cents = [];
        $remainders = [];
        $assigned = 0;

        foreach ($weights as $keyCode => $weight) {
            // Sem peso positivo (pedido sem lucro registrado) não há proporção a seguir:
            // divide igual, que é o mesmo que tratar todas as keys como equivalentes
            $exact = $totalWeight > 0
                ? $totalCents * $weight / $totalWeight
                : $totalCents / $count;

            $floor = (int) floor($exact);

            $cents[$keyCode] = $floor;
            $remainders[$keyCode] = $exact - $floor;
            $assigned += $floor;
        }

        // Os centavos que sobraram do truncamento vão para os maiores restos
        arsort($remainders);

        $leftover = max(0, $totalCents - $assigned);

        foreach (array_slice(array_keys($remainders), 0, $leftover) as $keyCode) {
            $cents[$keyCode]++;
        }

        return array_map(fn (int $value) => $value / 100, $cents);
    }
}
