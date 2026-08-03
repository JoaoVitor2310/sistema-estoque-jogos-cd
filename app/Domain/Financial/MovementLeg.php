<?php

namespace App\Domain\Financial;

use App\Domain\Enums\AccountType;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;

/**
 * Uma linha do extrato: o efeito de um lançamento sobre **uma** conta.
 *
 * Lançamentos que movem dinheiro entre contas produzem duas pernas (a saída e a
 * entrada), e a distribuição aos sócios produz uma por sócio. Quem persiste
 * grava cada perna como um `FinancialMovement`, todas com o mesmo `group_id`,
 * para que a exclusão remova o conjunto junto (ver docs/adr/0005).
 */
final class MovementLeg
{
    public function __construct(
        public readonly AccountType $account,
        public readonly MovementDirection $direction,
        public readonly MovementCategory $category,
        public readonly float $amount,
        public readonly ?float $quantity = null,
        public readonly ?float $unitPrice = null,
        public readonly ?int $partnerSlot = null,
    ) {}
}
