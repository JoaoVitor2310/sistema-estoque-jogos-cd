<?php

namespace App\Domain\Pricing;

/**
 * Calcula o preço de oferta a um fornecedor em keys TF2 para atingir
 * uma margem de lucro alvo sobre o income líquido do Gamivo.
 *
 * Fórmula: offer = net_income / (1 + profit_pct / 100) / tf2_price
 *
 * Exemplos de divisores:
 *   100% de lucro → divisor 2.0  (vendemos por 2× o custo)
 *    80% de lucro → divisor 1.8
 *    60% de lucro → divisor 1.6
 *
 * PHP puro — zero dependência do Laravel ou do banco de dados.
 */
final class OfferCalculator
{
    /** Margens padrão exibidas na calculadora de trades, em ordem decrescente de lucro. */
    public const PROFIT_TIERS = [100, 80, 60];

    /**
     * Calcula a quantidade de TF2 keys a oferecer para atingir uma margem de lucro alvo.
     *
     * @param  float  $netIncome     Income líquido após taxas Gamivo (€)
     * @param  float  $profitPercent Lucro alvo (ex: 100.0 para 100%)
     * @param  float  $tf2Price      Preço de 1 TF2 key em euros
     * @return float  Quantidade de TF2 keys a oferecer (pode ser fracionário)
     */
    public static function tf2Offer(float $netIncome, float $profitPercent, float $tf2Price): float
    {
        if ($tf2Price <= 0) {
            return 0.0;
        }

        return $netIncome / (1 + $profitPercent / 100) / $tf2Price;
    }
}
