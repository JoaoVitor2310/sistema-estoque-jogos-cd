<?php

namespace App\UseCases\Financial\DTO;

use App\Domain\Enums\AccountType;

/**
 * Entrada do saque dos sócios, já validada na fronteira HTTP.
 *
 * `partnerOneShare` é a fração do Sócio 1 (ex: 0.50) — o Sócio 2 leva o resto
 * exato. Vem sempre de quem lança: a tela a pré-preenche com a do mês, mas o
 * sistema nunca distribui sozinho (docs/adr/0005).
 */
final class DistributeToPartnersDTO
{
    public function __construct(
        public readonly AccountType $source,
        public readonly float $amount,
        public readonly float $partnerOneShare,
        public readonly ?string $description = null,
        public readonly ?string $occurredAt = null,
    ) {}
}
