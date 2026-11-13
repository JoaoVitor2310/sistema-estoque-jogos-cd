<?php

namespace App\Domain\Trades;

/**
 * O pedido já criou a trade de outro supplier.
 *
 * O mesmo `request_uuid` só devolve a trade que ele criou quando o pedido é
 * para o mesmo supplier. Devolvê-la a outro entregaria a quem chama o uuid de
 * uma trade alheia — e, pela leitura da API, a oferta e o código de entrega de
 * outra pessoa. Só acontece se quem chama reaproveitar o id por engano.
 */
final class RequestReusedForAnotherSupplier extends \RuntimeException
{
    public static function for(string $requestUuid): self
    {
        return new self("Request {$requestUuid} already created a trade for another supplier.");
    }
}
