<?php

namespace App\Domain\Enums;

/**
 * A natureza de uma saída (`MovementCategory::Expense`), para permitir
 * agrupar gastos por tipo (ex: um dashboard futuro). Lista enxuta de
 * propósito — se `Other` concentrar uma fração relevante dos lançamentos,
 * é sinal de que falta um valor real na lista.
 */
enum ExpenseCategory: string
{
    case Taxes = 'taxes';
    case Subscriptions = 'subscriptions';
    case Other = 'other';
}
