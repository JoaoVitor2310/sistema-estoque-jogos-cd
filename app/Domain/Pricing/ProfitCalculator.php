<?php

namespace App\Domain\Pricing;

/**
 * Cálculos de lucro de uma key.
 *
 * Todos os métodos retornam floats (ou null para indicar ausência de venda).
 * A formatação para exibição é responsabilidade da camada de apresentação.
 */
final class ProfitCalculator
{
    /**
     * Piso de custo usado nos cálculos de percentual para evitar divisão
     * por zero quando o custo individual não foi calculado (ex: lote sem TF2).
     * Representa €0.01 — custo mínimo assumido.
     */
    private const MINIMUM_COST = 0.01;

    /**
     * Custo saneado para uso nos cálculos: negativo não existe no domínio.
     *
     * Um custo negativo é lixo de dado (importação antiga, edição manual), e sem este
     * saneamento ele contamina tudo: entra somando no lucro absoluto e vira divisor
     * negativo no percentual, fazendo uma venda lucrativa aparecer com margem negativa.
     * Zero é legítimo — key de graça — e continua tratado pelo piso MINIMUM_COST na divisão.
     */
    public static function normalizeCost(float $individualCost): float
    {
        return max(0.0, $individualCost);
    }

    /**
     * Divisor seguro para os percentuais: nunca zero, nunca negativo.
     */
    private static function costDivisor(float $individualCost): float
    {
        $cost = self::normalizeCost($individualCost);

        return $cost == 0.0 ? self::MINIMUM_COST : $cost;
    }

    /**
     * Calcula o valor pago individualmente por uma key dentro de um lote.
     *
     * Fórmula: (qtdTF2 × tf2EuroPrice / somatorioIncomes) × gameIncome
     *
     * Retorna 0.0 quando somatorioIncomes ou gameIncome são zero
     * (divisão por zero ou income nulo indicam lote inválido).
     * Aplica um piso de 0.01 quando o resultado seria negativo ou zero
     * mas os inputs são válidos (ex: qtdTF2 = 0).
     */
    public static function individualCost(
        float $qtdTF2,
        float $tf2EuroPrice,
        float $somatorioIncomes,
        float $gameIncome
    ): float {
        if ($somatorioIncomes == 0.0 || $gameIncome == 0.0) {
            return 0.0;
        }

        $result = $qtdTF2 * $tf2EuroPrice / $somatorioIncomes * $gameIncome;

        return $result <= 0 ? self::MINIMUM_COST : round($result, 2);
    }

    /**
     * Lucro absoluto esperado na compra: income simulado − custo individual.
     *
     * Retorna 0.0 quando incomeSimulado é zero (key não calculada).
     */
    public static function purchaseProfit(float $incomeSimulado, float $individualCost): float
    {
        if ($incomeSimulado == 0.0) {
            return 0.0;
        }

        return round($incomeSimulado - self::normalizeCost($individualCost), 2);
    }

    /**
     * Lucro percentual esperado na compra: (lucroRS / custo individual) × 100.
     *
     * Retorna 0.0 quando lucroRS é zero — interpretado como sem lucro.
     * Custo zero (ou negativo, que é dado inválido) usa 0.01 como piso para evitar
     * divisão por zero — o percentual será muito alto (lucro "infinito").
     */
    public static function purchaseProfitPercent(float $lucroRS, float $individualCost): float
    {
        if ($lucroRS == 0.0) {
            return 0.0;
        }

        return round(($lucroRS / self::costDivisor($individualCost)) * 100, 2);
    }

    /**
     * Lucro absoluto ao vender: valor vendido − custo individual.
     *
     * Recebe null quando a key ainda não foi vendida — retorna null para
     * preservar essa distinção no banco (null ≠ lucro zero).
     */
    public static function saleProfit(?float $soldPrice, float $individualCost): ?float
    {
        if ($soldPrice === null) {
            return null;
        }

        return round($soldPrice - self::normalizeCost($individualCost), 2);
    }

    /**
     * Lucro percentual ao vender: (lucroVendaRS / custo individual) × 100.
     *
     * Retorna null quando saleProfit é null (key não vendida).
     * Retorna 0.0 quando saleProfit é zero — interpretado como sem lucro.
     * Custo zero (ou negativo, que é dado inválido) usa 0.01 como piso para evitar
     * divisão por zero.
     */
    public static function saleProfitPercent(?float $saleProfit, float $individualCost): ?float
    {
        if ($saleProfit === null) {
            return null;
        }

        if ($saleProfit == 0.0) {
            return 0.0;
        }

        return round(($saleProfit / self::costDivisor($individualCost)) * 100, 2);
    }

    /**
     * Margem consolidada de um conjunto de vendas: (Σ lucro / Σ custo) × 100.
     *
     * Equivale à média das margens individuais ponderada pelo custo de cada key —
     * cada venda pesa proporcionalmente ao capital que consumiu. Difere da média
     * simples das margens, onde uma key de €0,01 pesa igual a uma de €20 e uma
     * única venda barata com lucro percentual altíssimo distorce o indicador.
     *
     * Retorna 0.0 quando o custo total é zero (período sem vendas, ou vendas
     * cujo custo individual nunca foi calculado) — aqui o piso de MINIMUM_COST
     * não se aplica: num agregado ele produziria justamente o percentual
     * astronômico que a ponderação existe para evitar.
     */
    public static function weightedMarginPercent(float $totalSaleProfit, float $totalCost): float
    {
        if ($totalCost <= 0.0) {
            return 0.0;
        }

        return round(($totalSaleProfit / $totalCost) * 100, 2);
    }
}
