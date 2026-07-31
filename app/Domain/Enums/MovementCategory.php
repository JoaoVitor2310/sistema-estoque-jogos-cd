<?php

namespace App\Domain\Enums;

/**
 * Natureza de um movimento financeiro. Define o que ele representa e, em parte,
 * como o fechamento o trata.
 *
 * Movimentos manuais (lançados pelo usuário a qualquer momento):
 *  - Opening              : saldo de abertura (bootstrap do primeiro mês)
 *  - Income               : entrada no Principal (faturamento + receitas avulsas) — soma na cascata
 *  - Expense              : saída operacional do Principal (MEI, Claude…) — subtrai na cascata
 *  - Tf2Purchase          : compra real de TF2 keys (débito no Principal) — NÃO entra na cascata
 *                           (a reserva já é earmark); guarda qtd × preço para visualização
 *  - FundWithdrawal       : saque de uma das caixas (Reinvestment/Emergency)
 *
 * Movimentos gerados pelo fechamento (is_generated = true; a reabertura desfaz):
 *  - ReinvestmentTransfer : Principal → Reinvestment (20%)
 *  - EmergencyTransfer    : Principal → Emergency (10%)
 *  - PartnerDistribution  : débito no Principal para cada sócio
 */
enum MovementCategory: string
{
    case Opening = 'opening';
    case Income = 'income';
    case Expense = 'expense';
    case Tf2Purchase = 'tf2_purchase';
    case FundWithdrawal = 'fund_withdrawal';
    case ReinvestmentTransfer = 'reinvestment_transfer';
    case EmergencyTransfer = 'emergency_transfer';
    case PartnerDistribution = 'partner_distribution';
}
