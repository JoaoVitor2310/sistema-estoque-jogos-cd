<?php

namespace App\Domain\Trades;

/**
 * A moeda da trade não pode mudar: já há oferta feita nela.
 *
 * A oferta é um registro imutável na moeda em que o supplier a leu, e a
 * contraproposta segue essa moeda (docs/adr/0016). Trocar a da trade deixaria
 * as duas falando de unidades diferentes.
 */
final class TradeCurrencyLocked extends \RuntimeException
{
    public function __construct(int $tradeId)
    {
        parent::__construct("Trade {$tradeId} already has an offer; its currency cannot change.");
    }
}
