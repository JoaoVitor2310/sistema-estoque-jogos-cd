<?php

namespace App\Domain\Trades;

use App\Domain\Enums\CounterOfferRefusal;

/** A contraproposta foi recusada, e nada foi gravado. */
final class CounterOfferRefused extends \RuntimeException
{
    public function __construct(public readonly CounterOfferRefusal $reason)
    {
        parent::__construct("Counter offer refused: {$reason->value}.");
    }
}
