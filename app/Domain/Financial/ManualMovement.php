<?php

namespace App\Domain\Financial;

use App\Domain\Enums\AccountType;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;

/**
 * Um movimento simples lançado pelo usuário num fechamento em aberto: uma
 * entrada, uma saída ou uma compra real de TF2.
 *
 * A conta é escolhida por quem lança — o saque da Gamivo normalmente entra no
 * Principal, mas um gasto pode sair de qualquer conta. O que a categoria decide
 * é a **direção** e quais campos a acompanham:
 *
 *  - income       → crédito na conta escolhida
 *  - expense      → débito na conta escolhida
 *  - tf2_purchase → débito na conta Tf2 (gasta a verba do mês); `amount = qtd × preço`
 *
 * Débito numa caixinha (Reinvestimento/Emergência) exige justificativa — é a
 * proteção de auditoria que sobreviveu à remoção da categoria `fund_withdrawal`
 * (ver docs/adr/0005).
 *
 * Lançamentos que geram mais de uma linha (transferência, distribuição) não
 * passam por aqui: têm UseCases próprios, porque precisam de `group_id`.
 */
final class ManualMovement
{
    private function __construct(
        public readonly AccountType $account,
        public readonly MovementDirection $direction,
        public readonly MovementCategory $category,
        public readonly float $amount,
        public readonly ?float $quantity,
        public readonly ?float $unitPrice,
    ) {}

    public static function make(
        MovementCategory $category,
        ?AccountType $account = null,
        ?float $amount = null,
        ?float $quantity = null,
        ?float $unitPrice = null,
        ?string $description = null,
    ): self {
        $movement = match ($category) {
            MovementCategory::Income => new self(
                self::requireAccount($account),
                MovementDirection::Credit,
                $category,
                self::requirePositive($amount, 'amount'),
                null,
                null,
            ),
            MovementCategory::Expense => new self(
                self::requireAccount($account),
                MovementDirection::Debit,
                $category,
                self::requirePositive($amount, 'amount'),
                null,
                null,
            ),
            // A compra real sempre gasta a verba do mês, então a conta não é escolhível.
            MovementCategory::Tf2Purchase => self::tf2Purchase(
                self::requirePositive($quantity, 'quantity'),
                self::requirePositive($unitPrice, 'unitPrice'),
            ),
            default => throw new \InvalidArgumentException(
                "Category {$category->value} cannot be recorded as a simple movement."
            ),
        };

        $movement->guardFundJustification($description);

        return $movement;
    }

    private static function tf2Purchase(float $quantity, float $unitPrice): self
    {
        return new self(
            AccountType::Tf2,
            MovementDirection::Debit,
            MovementCategory::Tf2Purchase,
            Money::fromReais($quantity * $unitPrice)->toReais(),
            $quantity,
            $unitPrice,
        );
    }

    /**
     * Tirar dinheiro de uma caixinha exige dizer por quê — o histórico das
     * reservas não pode ter saída sem motivo registrado.
     */
    private function guardFundJustification(?string $description): void
    {
        if ($this->direction === MovementDirection::Debit
            && $this->account->isFund()
            && ($description === null || trim($description) === '')
        ) {
            throw new \InvalidArgumentException('Debiting a reserve fund requires a justification.');
        }
    }

    private static function requireAccount(?AccountType $account): AccountType
    {
        if ($account === null) {
            throw new \InvalidArgumentException('An account must be chosen for this movement.');
        }

        return $account;
    }

    private static function requirePositive(?float $value, string $field): float
    {
        if ($value === null || $value <= 0) {
            throw new \InvalidArgumentException("Field {$field} must be a positive value.");
        }

        return $value;
    }
}
