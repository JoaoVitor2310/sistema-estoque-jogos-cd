<?php

namespace App\Domain\Enums;

/**
 * Estado de um fechamento mensal (FinancialMonth).
 *
 *  - Draft  : mês corrente sendo montado (movimentos livres, editável)
 *  - Closed : fechado e imutável — snapshot congelado, vira histórico
 */
enum FinancialMonthStatus: string
{
    case Draft = 'draft';
    case Closed = 'closed';
}
