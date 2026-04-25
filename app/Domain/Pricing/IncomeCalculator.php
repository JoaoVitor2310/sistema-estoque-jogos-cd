<?php

namespace App\Domain\Pricing;

use App\Domain\Pricing\ValueObjects\MarketplaceFee;

/**
 * Calcula o income líquido do Gamivo após as taxas do marketplace.
 *
 * PHP puro — zero dependência do Laravel ou do banco de dados.
 * O VO MarketplaceFee com as taxas deve ser fornecido pelo chamador
 * (quem carrega do banco é o KeyCalculationService).
 *
 * Tiers Gamivo:
 *  marketPrice < 0.28 → marketPrice − 0.11 (taxa micro, fixa)
 *  marketPrice < 8    → marketPrice × (1 − percentLow) − fixedLow
 *  marketPrice >= 8   → marketPrice × (1 − percentHigh) − fixedHigh
 */
final class IncomeCalculator
{
    /**
     * Preço abaixo do qual se aplica a taxa micro (tier especial da Gamivo).
     * Hardcoded pois é uma regra da plataforma, não configurável via banco.
     */
    private const MICRO_THRESHOLD = 0.28;

    /**
     * Taxa fixa do tier micro.
     * Para preços abaixo de €0.28 a Gamivo cobra apenas €0.11 fixo.
     */
    private const MICRO_FIXED_FEE = 0.11;

    /**
     * Preço a partir do qual se aplica o tier superior de taxas.
     */
    private const TIER_THRESHOLD = 8.0;

    /**
     * Calcula o income líquido após as taxas da Gamivo.
     *
     * @param  float  $marketPrice  Preço de venda no marketplace (€)
     * @param  MarketplaceFee  $fee  Taxas vigentes da Gamivo
     * @return float Income líquido (€)
     */
    public static function forGamivo(float $marketPrice, MarketplaceFee $fee): float
    {
        if ($marketPrice < self::MICRO_THRESHOLD) {
            return $marketPrice - self::MICRO_FIXED_FEE;
        }

        if ($marketPrice < self::TIER_THRESHOLD) {
            return $marketPrice * (1 - $fee->percentLow) - $fee->fixedLow;
        }

        return $marketPrice * (1 - $fee->percentHigh) - $fee->fixedHigh;
    }
}
