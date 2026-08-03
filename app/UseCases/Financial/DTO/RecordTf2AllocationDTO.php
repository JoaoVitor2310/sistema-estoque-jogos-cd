<?php

namespace App\UseCases\Financial\DTO;

/**
 * Entrada da verba de TF2 do mês, já validada na fronteira HTTP.
 *
 * A meta mora no próprio lançamento (qtd × preço unitário) — não há coluna de
 * meta declarada que possa divergir do dinheiro efetivamente movido. Lançar um
 * segundo `tf2_allocation` no meio do mês complementa o orçamento.
 */
final class RecordTf2AllocationDTO
{
    public function __construct(
        public readonly float $quantity,
        public readonly float $unitPrice,
        public readonly ?string $description = null,
        public readonly ?string $occurredAt = null,
    ) {}
}
