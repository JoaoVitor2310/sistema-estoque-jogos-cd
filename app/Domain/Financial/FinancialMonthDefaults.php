<?php

namespace App\Domain\Financial;

/**
 * Padrões de domínio para um novo fechamento mensal.
 *
 * Estes valores **nunca são aplicados sozinhos** — servem só para pré-preencher
 * os formulários de lançamento, que o usuário confirma explicitamente (ver
 * docs/adr/0005). Cada `FinancialMonth` guarda o valor vigente como snapshot e o
 * repassa ao mês seguinte, então alterar um default aqui não reescreve histórico.
 */
final class FinancialMonthDefaults
{
    /** Fração sugerida do saldo de origem ao abastecer o reinvestimento (20%). */
    public const REINVESTMENT_PERCENT = 0.20;

    /** Fração sugerida do saldo de origem ao abastecer a emergência (10%). */
    public const EMERGENCY_PERCENT = 0.10;

    /** Fração sugerida do Sócio 1 na distribuição (50%); o Sócio 2 leva o resto. */
    public const PARTNER_ONE_SHARE = 0.50;
}
