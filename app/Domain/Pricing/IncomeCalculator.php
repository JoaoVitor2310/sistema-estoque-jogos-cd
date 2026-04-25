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
 *  precoCliente < 0.28 → precoCliente − 0.11 (taxa micro, fixa)
 *  precoCliente < 8    → precoCliente × (1 − percentLow) − fixedLow
 *  precoCliente >= 8   → precoCliente × (1 − percentHigh) − fixedHigh
 */
final class IncomeCalculator
{
    /**
     * Preço abaixo do qual se aplica a taxa micro (tier especial da Gamivo).
     * Hardcoded pois é uma regra da plataforma, não configurável via banco.
     */
    private const MICRO_THRESHOLD = 0.28;

    /**
     * Taxa fixa do tier micro — distinta do fixoMenor convencional (€0.25).
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
     * @param  float  $clientPrice  Preço de venda no marketplace (€)
     * @param  MarketplaceFee  $fee  Taxas vigentes da Gamivo
     * @return float Income líquido (€)
     */
    public static function forGamivo(float $clientPrice, MarketplaceFee $fee): float
    {
        if ($clientPrice < self::MICRO_THRESHOLD) {
            return $clientPrice - self::MICRO_FIXED_FEE;
        }

        if ($clientPrice < self::TIER_THRESHOLD) {
            return $clientPrice * (1 - $fee->percentLow) - $fee->fixedLow;
        }

        return $clientPrice * (1 - $fee->percentHigh) - $fee->fixedHigh;
    }
}
