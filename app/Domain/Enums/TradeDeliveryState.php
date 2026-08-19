<?php

namespace App\Domain\Enums;

use DateTimeInterface;

/**
 * Em que ponto da entrega uma trade está.
 *
 * **Não existe coluna de status** — o estado é derivado de duas colunas que já
 * significam alguma coisa sozinhas (ver docs/adr/0008). Uma coluna a mais seria
 * um terceiro lugar para ficar dessincronizado dos outros dois.
 *
 * A existência do `delivery_uuid` **não** entra aqui: toda trade nasce com
 * credencial, então ter link não distingue trade nenhuma — e ter link não quer
 * dizer que ele foi mandado. Quem registra o envio é o `message_sent` da trade,
 * marcado por quem mandou.
 */
enum TradeDeliveryState: string
{
    /** O supplier ainda não apertou o botão de entregar. */
    case Negotiating = 'negotiating';

    /** A fila de conferência: ele entregou, a equipe ainda não importou. */
    case AwaitingReview = 'awaiting_review';

    /** As keys entraram no estoque; a credencial da entrega morreu aqui. */
    case Imported = 'imported';

    /**
     * A ordem de avaliação é a inversa da leitura acima: `is_imported` vem
     * primeiro porque é o estado terminal — uma trade importada continua tendo
     * `delivered_at` preenchido, e sem essa precedência cairia em
     * [[self::AwaitingReview]] para sempre.
     */
    public static function resolve(?DateTimeInterface $deliveredAt, bool $isImported): self
    {
        if ($isImported) {
            return self::Imported;
        }

        return $deliveredAt === null ? self::Negotiating : self::AwaitingReview;
    }

    /**
     * Se a página da entrega ainda aceita escrita do supplier.
     *
     * Só enquanto ele negocia. O clique em entregar fecha a página: a partir
     * dele o que está lá é o que ele declarou, e nem ele nem uma sessão ainda
     * viva mudam mais nada — correção depois disso passa pela equipe, que edita
     * pela aba interna. É o que impede que uma trade entregue e ainda não
     * importada seja esvaziada por quem já a entregou.
     *
     * Não confundir com a página *responder*: entregue ela segue abrindo, em
     * leitura, para ele conferir o que mandou.
     */
    public function acceptsSupplierWrites(): bool
    {
        return $this === self::Negotiating;
    }
}
