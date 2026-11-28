<?php

namespace App\Domain\Trades;

use App\Domain\Enums\ChatDeliveryRefusal;

/** O preenchimento ou a entrega pelo chat foi recusado, e nada foi gravado. */
final class ChatDeliveryRefused extends \RuntimeException
{
    /**
     * @param  list<int>  $lineIds  as linhas que causaram a recusa: fora da oferta, ou sem key
     */
    public function __construct(
        public readonly ChatDeliveryRefusal $reason,
        public readonly array $lineIds = [],
    ) {
        parent::__construct("Chat delivery refused: {$reason->value}.");
    }
}
