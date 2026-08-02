<?php

namespace App\UseCases\Financial;

use App\Domain\Enums\AccountType;
use App\Domain\Enums\MovementCategory;

/**
 * Entrada de um lançamento simples, já validada na fronteira HTTP.
 *
 * Carrega primitivos e enums — nada de domínio construído: quem monta o VO
 * `ManualMovement` (e portanto quem pode falhar por regra de negócio) é o
 * UseCase, para que o erro nasça na camada que sabe reportá-lo.
 */
final class RecordMovementData
{
    public function __construct(
        public readonly MovementCategory $category,
        public readonly ?AccountType $account = null,
        public readonly ?float $amount = null,
        public readonly ?float $quantity = null,
        public readonly ?float $unitPrice = null,
        public readonly ?string $description = null,
        public readonly ?string $occurredAt = null,
    ) {}
}
