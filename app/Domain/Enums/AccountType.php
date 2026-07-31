<?php

namespace App\Domain\Enums;

/**
 * As três contas do domínio financeiro. Saldo de cada uma é derivado da soma
 * dos movimentos (não há coluna de saldo persistida).
 *
 *  - Principal      : caixa operacional (faturamento entra; despesas, compras
 *                     de TF2, transferências e distribuições saem)
 *  - Reinvestment   : caixa de reinvestimento (recebe o depósito no fechamento)
 *  - Emergency      : caixa de emergência (recebe o depósito no fechamento)
 */
enum AccountType: string
{
    case Principal = 'principal';
    case Reinvestment = 'reinvestment';
    case Emergency = 'emergency';
}
