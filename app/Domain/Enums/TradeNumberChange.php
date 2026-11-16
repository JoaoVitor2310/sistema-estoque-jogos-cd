<?php

namespace App\Domain\Enums;

/**
 * O que acontece com o número da trade quando a equipe muda o supplier dela.
 *
 * O número só vale dentro do supplier: é o que ele digita no chat. Trocar o
 * supplier troca o número, e o número antigo não volta para o supplier antigo
 * (o contador dele só sobe). Regras em docs/PRODUCT.md, "Número da trade".
 */
enum TradeNumberChange
{
    /** Mesmo supplier (ou continua sem nenhum): o número fica. */
    case Keep;

    /** Ficou sem supplier: número não significa nada sem ele. */
    case Drop;

    /** Supplier novo: o próximo número dele. */
    case NextOfNewSupplier;

    public static function onSupplierChange(?int $from, ?int $to): self
    {
        return match (true) {
            $from === $to => self::Keep,
            $to === null => self::Drop,
            default => self::NextOfNewSupplier,
        };
    }
}
