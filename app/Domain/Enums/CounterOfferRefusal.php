<?php

namespace App\Domain\Enums;

/**
 * Por que uma contraproposta não foi feita. O valor é o `reason` que volta a
 * quem pediu.
 */
enum CounterOfferRefusal: string
{
    /** A trade já foi entregue ou importada: a negociação acabou. */
    case TradeNotNegotiating = 'trade_not_negotiating';

    /** A trade não tem oferta para contrapropor (nasceu por outro caminho). */
    case NoOffer = 'no_offer';

    /** A vigente é a da última rodada (`SupplierOffer::MAX_ROUNDS`). */
    case OfferIsFinal = 'offer_is_final';

    /** O pedido não passa da oferta vigente: aceitar é pela página da entrega. */
    case NotAnIncrease = 'not_an_increase';

    /** Nenhum jogo pode subir nesta rodada: a vigente já é o melhor que se oferta. */
    case NothingToConcede = 'nothing_to_concede';
}
