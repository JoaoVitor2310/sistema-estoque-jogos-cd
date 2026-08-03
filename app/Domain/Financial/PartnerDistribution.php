<?php

namespace App\Domain\Financial;

use App\Domain\Enums\AccountType;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;

/**
 * O saque dos dois sócios, num único lançamento.
 *
 * Diferente de um `AccountTransfer`: aqui as duas pernas são **débitos na mesma
 * conta**, uma por sócio, porque o dinheiro sai da empresa e não tem
 * contrapartida em conta nenhuma. A origem costuma ser o Principal, mas quem
 * lança escolhe.
 *
 * A divisão fica com o `PartnerSplit`, que garante a reconciliação exata e manda
 * o centavo órfão para o Sócio 1. Os sócios são identificados por posição
 * (`partnerSlot` 1 ou 2) — o sistema não guarda nome (ver docs/adr/0005).
 */
final class PartnerDistribution
{
    private function __construct(
        public readonly AccountType $source,
        public readonly PartnerSplit $split,
    ) {}

    public static function from(
        AccountType $source,
        float $amount,
        float $partnerOneShare,
        ?string $description = null,
    ): self {
        JustificationPolicy::guardDebit($source, $description);

        return new self($source, PartnerSplit::divide($amount, $partnerOneShare));
    }

    /**
     * Um débito por sócio. Uma parte zerada não vira linha — um saque de R$ 0
     * seria ruído no extrato.
     *
     * @return list<MovementLeg>
     */
    public function legs(): array
    {
        $legs = [];

        foreach ([1 => $this->split->partnerOne, 2 => $this->split->partnerTwo] as $slot => $amount) {
            if ($amount <= 0) {
                continue;
            }

            $legs[] = new MovementLeg(
                $this->source,
                MovementDirection::Debit,
                MovementCategory::PartnerDistribution,
                $amount,
                partnerSlot: $slot,
            );
        }

        return $legs;
    }
}
