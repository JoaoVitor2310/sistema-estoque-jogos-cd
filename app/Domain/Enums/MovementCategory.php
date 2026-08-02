<?php

namespace App\Domain\Enums;

/**
 * Natureza de um movimento financeiro. Define o que ele representa e quais
 * campos o acompanham.
 *
 * Lançados pelo usuário (ver o fluxo do mês em docs/adr/0005):
 *  - Income              : entrada na conta escolhida (saque da Gamivo, receitas avulsas)
 *  - Expense             : saída da conta escolhida (impostos, Claude…)
 *  - Tf2Allocation       : define a verba de TF2 do mês — débito no Principal e
 *                          crédito no Tf2, com `quantity` × `unit_price`
 *  - Tf2Purchase         : compra real de TF2 keys, debitando a verba (conta Tf2);
 *                          guarda `quantity` × `unit_price`
 *  - Transfer            : move dinheiro entre duas contas quaisquer — o par de
 *                          contas já declara a intenção (ex: Principal→Reinvestment)
 *  - PartnerDistribution : saque de um sócio; sai da empresa, sem contrapartida em
 *                          conta. Identificado por `partner_slot` (1 ou 2)
 *
 * Gerados pelo sistema (`is_generated = true`; a reabertura desfaz):
 *  - Opening             : saldo de abertura do mês (bootstrap ou carry-forward)
 *  - Transfer            : a devolução da sobra da verba de TF2 ao Principal, no
 *                          fechamento — único movimento que o `close` gera
 *
 * Movimentos que nascem do mesmo lançamento (as duas pernas de uma transferência,
 * os dois débitos de uma distribuição) compartilham `group_id` e são apagados juntos.
 */
enum MovementCategory: string
{
    case Opening = 'opening';
    case Income = 'income';
    case Expense = 'expense';
    case Tf2Allocation = 'tf2_allocation';
    case Tf2Purchase = 'tf2_purchase';
    case Transfer = 'transfer';
    case PartnerDistribution = 'partner_distribution';
}
