<?php

namespace App\Domain\Enums;

/**
 * Como o líquido de um pedido da Gamivo foi atribuído às keys entregues.
 *
 * Produzido por [[App\UseCases\Marketplaces\Gamivo\UpdateSoldOffersUseCase]]. O
 * histórico de vendas traz uma linha por oferta e os detalhes do pedido trazem as
 * keys, sem dizer qual key é de qual linha — o casamento é feito pelo `product_id`
 * da linha contra o `gamivo_id` da key. Cada caso registra o quanto desse casamento
 * fechou, porque só `Matched` garante que cada key recebeu o valor da própria oferta.
 */
enum OrderPayoutAttribution: string
{
    /** Toda linha achou suas keys: cada key recebeu o líquido da própria oferta. */
    case Matched = 'matched';

    /** Parte das linhas casou; o resto do bruto foi dividido entre as keys que sobraram. */
    case PartiallyMatched = 'partially_matched';

    /** Nada casou (ou sobrou linha sem key): o bruto do pedido foi dividido por igual. */
    case EqualSplit = 'equal_split';

    /** O endpoint de detalhes não devolveu o pedido — nenhuma key foi tocada. */
    case NoOrderDetails = 'no_order_details';

    /** O pedido não entregou key de texto (produto entregue por outro meio). */
    case NoTextKeys = 'no_text_keys';
}
