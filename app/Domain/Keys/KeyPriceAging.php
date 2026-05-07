<?php

namespace App\Domain\Keys;

/**
 * Regras de degradação de preço mínimo para keys que não estão vendendo.
 *
 * Quem consulta a API externa (preço atual do mercado) e persiste
 * o resultado é o Service — o Domain só recebe os valores e decide.
 *
 * Duas estratégias:
 *  - Aging:  degradação progressiva por tempo listado (aplicada em batch periódico)
 *  - Limbo:  key listada há 12+ meses, ajuste baseado no preço atual do mercado
 */
final class KeyPriceAging
{
    /** Preço piso absoluto — usado quando a key precisa ser liquidada. */
    private const FLOOR_PRICE = 0.02;

    /** Meses listada sem venda a partir dos quais a key é considerada em limbo. */
    public const LIMBO_MONTHS_THRESHOLD = 12;

    /** Percentual do preço de mercado atual aceito para keys em limbo (10%). */
    public const LIMBO_MARKET_DISCOUNT = 0.10;

    /** Multiplicadores de degradação por faixa de meses listada. */
    private const AGING_TIERS = [
        self::LIMBO_MONTHS_THRESHOLD => null,  // >= 12 meses: preço piso (liquidar)
        9 => 1.2,   // >= 9 meses:  custo × 1.2
        6 => 1.3,   // >= 6 meses:  custo × 1.3
        3 => 1.4,   // >= 3 meses:  custo × 1.4
    ];

    /**
     * Calcula o novo preço mínimo com base no tempo que a key está listada.
     *
     * Retorna null quando o tempo ainda não atingiu o primeiro tier (< 3 meses),
     * sinalizando que nenhuma alteração deve ser feita.
     *
     * @param  float  $individualCost  Custo individual da key (€)
     * @param  int  $monthsListed  Meses desde que a key foi listada para venda
     * @return float|null Novo preço mínimo, ou null se sem alteração
     */
    public static function calculateAgedPrice(float $individualCost, int $monthsListed): ?float
    {
        foreach (self::AGING_TIERS as $threshold => $multiplier) {
            if ($monthsListed >= $threshold) {
                return $multiplier === null
                    ? self::FLOOR_PRICE
                    : $individualCost * $multiplier;
            }
        }

        return null;
    }

    /**
     * Calcula o preço mínimo para uma key em limbo (12+ meses listada, não vendida).
     *
     * Estratégia: se o mercado está pagando menos do que o custo da key,
     * a única saída é liquidar no piso. Caso contrário, aceita 10% do preço
     * atual de mercado para dar chance de venda.
     *
     * @param  float  $individualCost  Custo individual da key (€)
     * @param  float  $actualMarketPrice  Preço atual da key no mercado Gamivo (€)
     * @return float Novo preço mínimo (€)
     */
    public static function calculateLimboPrice(float $individualCost, float $actualMarketPrice): float
    {
        if ($actualMarketPrice < $individualCost) {
            return self::FLOOR_PRICE;
        }

        return $actualMarketPrice * self::LIMBO_MARKET_DISCOUNT;
    }
}
