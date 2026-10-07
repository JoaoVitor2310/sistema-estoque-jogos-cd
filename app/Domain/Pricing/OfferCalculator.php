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
 */
final class OfferCalculator
{
    /** Margens padrão exibidas na calculadora de trades, em ordem decrescente de lucro. */
    public const PROFIT_TIERS = [100, 80, 60];

    /** Margem de lucro usada na prospecção automática de fornecedores novos (ProspectSupplierUseCase). */
    public const NEW_SUPPLIER_PROFIT_PERCENT = 70;

    /** Menor oferta em dinheiro que se publica: o fornecedor nunca lê "0.00" ao lado de um jogo. */
    public const MIN_CASH_OFFER = 0.01;

    /** Casas decimais de um valor em dinheiro e de `trades.amount`. */
    public const MONEY_DECIMALS = 2;

    /**
     * Calcula a quantidade de TF2 keys a oferecer para atingir uma margem de lucro alvo.
     *
     * @param  float  $netIncome  Income líquido após taxas Gamivo (€)
     * @param  float  $profitPercent  Lucro alvo (ex: 100.0 para 100%)
     * @param  float  $tf2Price  Preço de 1 TF2 key em euros
     * @return float Quantidade de TF2 keys a oferecer (pode ser fracionário)
     */
    public static function tf2Offer(float $netIncome, float $profitPercent, float $tf2Price): float
    {
        if ($tf2Price <= 0) {
            return 0.0;
        }

        return $netIncome / (1 + $profitPercent / 100) / $tf2Price;
    }

    /**
     * Converte uma oferta em TF2 keys para a moeda de pagamento (euro ou dólar).
     *
     * Passa pela TF2 de propósito: a oferta nasce em TF2 e a cotação da TF2 em
     * cada moeda é a que o sistema já mantém. Arredonda a 2 casas, que é o que o
     * fornecedor lê no comentário, nunca abaixo de MIN_CASH_OFFER (a oferta em TF2 já
     * foi tida como positiva: só o arredondamento a levaria a zero).
     *
     * @param  float  $tf2Offer  Quantidade de TF2 keys a oferecer
     * @param  float  $tf2PriceInCurrency  Preço de 1 TF2 key na moeda de pagamento
     */
    public static function inCurrency(float $tf2Offer, float $tf2PriceInCurrency): float
    {
        return max(self::MIN_CASH_OFFER, round($tf2Offer * $tf2PriceInCurrency, self::MONEY_DECIMALS));
    }

    /**
     * Converte um valor pago em dinheiro para a quantidade equivalente de TF2
     * keys, no preço de hoje, com as 2 casas de `trades.amount` — a unidade e a
     * precisão em que o rateio do custo do lote, e tudo que o exibe, já operam.
     *
     * @param  float  $amount  Valor acertado, na moeda de pagamento
     * @param  float  $tf2PriceInCurrency  Preço de 1 TF2 key na mesma moeda
     */
    public static function toTf2Quantity(float $amount, float $tf2PriceInCurrency): float
    {
        if ($tf2PriceInCurrency <= 0) {
            return 0.0;
        }

        return round($amount / $tf2PriceInCurrency, self::MONEY_DECIMALS);
    }
}
