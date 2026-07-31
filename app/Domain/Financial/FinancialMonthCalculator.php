<?php

namespace App\Domain\Financial;

/**
 * Cascata determinística do fechamento mensal.
 *
 * Ordem fixa (a regra de negócio proíbe alterá-la):
 *   1. Σ entradas do mês
 *   2. − Reserva TF2 (meta × preço)  → earmark virtual (não sai do Principal)
 *   3. − Σ saídas operacionais
 *   4. = Saldo base
 *   5. − Reinvestimento (% do saldo base)      → transferência p/ a caixa
 *   6. − Emergência (% do saldo pós-reinvest)   → transferência p/ a caixa
 *   7. = Distribuível ÷ 2 (split configurável)  → sócios
 *
 * Arredondamento: half-up a cada passo, encapsulado no VO `Money` (centavos
 * inteiros). O Sócio 2 leva o resto exato do distribuível, então
 * sócio1 + sócio2 sempre reconcilia e o centavo órfão fica com o Sócio 1.
 */
final class FinancialMonthCalculator
{
    /**
     * @param  float  $totalIncome  Σ entradas do mês (R$)
     * @param  float  $tf2TargetQuantity  Meta de TF2 keys do mês
     * @param  float  $tf2Price  Preço estimado por TF2 key (R$)
     * @param  float  $totalExpenses  Σ saídas operacionais do mês (R$)
     * @param  float  $reinvestmentPercent  Fração do saldo base (ex: 0.20)
     * @param  float  $emergencyPercent  Fração do saldo pós-reinvest (ex: 0.10)
     * @param  float  $partnerOneShare  Fração do distribuível do Sócio 1 (ex: 0.50); Sócio 2 leva o resto
     */
    public static function calculate(
        float $totalIncome,
        float $tf2TargetQuantity,
        float $tf2Price,
        float $totalExpenses,
        float $reinvestmentPercent,
        float $emergencyPercent,
        float $partnerOneShare,
    ): FinancialMonthResult {
        $tf2Reserve = Money::fromReais($tf2TargetQuantity * $tf2Price);
        $base = Money::fromReais($totalIncome)
            ->minus($tf2Reserve)
            ->minus(Money::fromReais($totalExpenses));

        $reinvestment = $base->percentage($reinvestmentPercent);
        $afterReinvestment = $base->minus($reinvestment);

        $emergency = $afterReinvestment->percentage($emergencyPercent);
        $distributable = $afterReinvestment->minus($emergency);

        // Sócio 1 arredondado; Sócio 2 leva o resto exato → reconciliação garantida,
        // centavo órfão com o Sócio 1.
        $partnerOne = $distributable->percentage($partnerOneShare);
        $partnerTwo = $distributable->minus($partnerOne);

        return new FinancialMonthResult(
            tf2Reserve: $tf2Reserve->toReais(),
            base: $base->toReais(),
            reinvestment: $reinvestment->toReais(),
            afterReinvestment: $afterReinvestment->toReais(),
            emergency: $emergency->toReais(),
            distributable: $distributable->toReais(),
            partnerOne: $partnerOne->toReais(),
            partnerTwo: $partnerTwo->toReais(),
        );
    }
}
