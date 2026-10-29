<?php

namespace App\Domain\Enums;

use App\Domain\Pricing\OfferCalculator;

/**
 * A categoria de um supplier, marcada à mão pela equipe, e o lucro inicial de
 * oferta que cada uma define.
 *
 * O supplier **sem categoria** (`category` nula) é o Normal — é como nasce todo
 * supplier novo. Por isso o Normal não é um caso do enum: ele é a ausência de
 * categoria, e [[self::initialProfitPercent]] recebe a categoria anulável.
 *
 * O percentual é de lucro sobre o income líquido da Gamivo
 * ([[OfferCalculator::tf2Offer]]): lucro maior, oferta menor.
 */
enum SupplierCategory: string
{
    case Vip = 'vip';
    case Retail = 'retail';
    case Blocked = 'blocked';

    public const VIP_INITIAL_PROFIT_PERCENT = 80;

    /** O Normal é o supplier novo, o mesmo lucro da prospecção de fornecedores. */
    public const NORMAL_INITIAL_PROFIT_PERCENT = OfferCalculator::NEW_SUPPLIER_PROFIT_PERCENT;

    public const RETAIL_INITIAL_PROFIT_PERCENT = 60;

    /**
     * Lucro inicial da oferta a um supplier desta categoria.
     *
     * `null` para o bloqueado: não há lucro inicial para ele, e devolver um
     * percentual qualquer deixaria quem chama ofertar a ele por engano.
     */
    public static function initialProfitPercent(?self $category): ?int
    {
        return match ($category) {
            null => self::NORMAL_INITIAL_PROFIT_PERCENT,
            self::Vip => self::VIP_INITIAL_PROFIT_PERCENT,
            self::Retail => self::RETAIL_INITIAL_PROFIT_PERCENT,
            self::Blocked => null,
        };
    }
}
