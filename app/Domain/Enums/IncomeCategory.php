<?php

namespace App\Domain\Enums;

/**
 * A origem de uma entrada (`MovementCategory::Income`), para permitir
 * agrupar receitas por fonte (ex: um dashboard futuro). Lista enxuta de
 * propósito — se `Other` concentrar uma fração relevante dos lançamentos,
 * é sinal de que falta um valor real na lista.
 */
enum IncomeCategory: string
{
    case GamivoPayout = 'gamivo_payout';
    case ExternalInvestment = 'external_investment';
    case Other = 'other';
}
