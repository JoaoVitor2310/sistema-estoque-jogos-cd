<?php

namespace App\Domain\Financial;

/**
 * Padrões de domínio para um novo fechamento mensal.
 *
 * Fonte única dos valores default usados no bootstrap (primeiro mês) e como
 * ponto de partida antes do carry-forward. Cada `FinancialMonth` guarda o valor
 * efetivamente usado como snapshot — alterar um default aqui só afeta meses
 * futuros, nunca reescreve histórico.
 */
final class FinancialMonthDefaults
{
    /** Fração do saldo base destinada ao reinvestimento (20%). */
    public const REINVESTMENT_PERCENT = 0.20;

    /** Fração do saldo pós-reinvest destinada à emergência (10%). */
    public const EMERGENCY_PERCENT = 0.10;

    /** Fração do distribuível do Sócio 1 (50%); o Sócio 2 leva o resto. */
    public const PARTNER_ONE_SHARE = 0.50;

    /** Aumento mensal da meta de TF2 keys aplicado no fechamento (+10). */
    public const TF2_MONTHLY_INCREMENT = 10;
}
