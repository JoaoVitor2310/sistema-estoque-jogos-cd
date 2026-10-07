<?php

namespace App\Domain\Pricing;

use App\Domain\Enums\TradeCurrency;

/**
 * Não há cotação da TF2 na moeda pedida, então a oferta não pode ser convertida.
 *
 * Erro e não valor padrão: uma oferta em outra moeda que o fornecedor lê no
 * comentário público é pior do que nenhuma oferta.
 */
final class CurrencyPriceUnavailable extends \RuntimeException
{
    public static function for(TradeCurrency $currency): self
    {
        return new self("No TF2 price available in {$currency->value}.");
    }
}
