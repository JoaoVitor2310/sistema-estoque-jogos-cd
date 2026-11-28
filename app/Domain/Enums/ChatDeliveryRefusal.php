<?php

namespace App\Domain\Enums;

/**
 * Por que o preenchimento das keys ou a entrega pelo chat não aconteceu. O
 * valor é o `reason` que volta ao chatbot.
 */
enum ChatDeliveryRefusal: string
{
    /** A trade já foi entregue ou importada, e a escrita do supplier fechou. */
    case TradeNotNegotiating = 'trade_not_negotiating';

    /** A trade não tem oferta com jogo a entregar: não há modelo para colar. */
    case NoOffer = 'no_offer';

    /** O pedido traz uma linha que não está na oferta vigente. */
    case LineNotInOffer = 'line_not_in_offer';

    /** Falta key em alguma linha da oferta: a entrega pelo chat é só completa. */
    case MissingKeys = 'missing_keys';

    /** A trade já foi entregue: repetir a entrega não muda nada. */
    case AlreadyDelivered = 'already_delivered';
}
