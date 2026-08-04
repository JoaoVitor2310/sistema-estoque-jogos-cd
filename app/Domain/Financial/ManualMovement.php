<?php

namespace App\Domain\Financial;

use App\Domain\Enums\AccountType;
use App\Domain\Enums\ExpenseCategory;
use App\Domain\Enums\IncomeCategory;
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
 *  - income       → crédito na conta escolhida; exige um `IncomeCategory` (a origem)
 *  - expense      → débito na conta escolhida; exige um `ExpenseCategory` (a natureza)
 *  - tf2_purchase → débito na conta Tf2 (gasta a verba do mês); `amount = qtd × preço`
 *
 * Débito numa caixinha exige justificativa — a regra vive na `JustificationPolicy`,
 * compartilhada com os demais lançamentos.
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
        public readonly ?ExpenseCategory $expenseCategory = null,
        public readonly ?IncomeCategory $incomeCategory = null,
    ) {}

    public static function make(
        MovementCategory $category,
        ?AccountType $account = null,
        ?float $amount = null,
        ?float $quantity = null,
        ?float $unitPrice = null,
        ?string $description = null,
        ?ExpenseCategory $expenseCategory = null,
        ?IncomeCategory $incomeCategory = null,
    ): self {
        $movement = match ($category) {
            MovementCategory::Income => new self(
                self::requireAccount($account),
                MovementDirection::Credit,
                $category,
                self::requirePositive($amount, 'amount'),
                null,
                null,
                incomeCategory: self::requireIncomeCategory($incomeCategory),
            ),
            MovementCategory::Expense => new self(
                self::requireAccount($account),
                MovementDirection::Debit,
                $category,
                self::requirePositive($amount, 'amount'),
                null,
                null,
                expenseCategory: self::requireExpenseCategory($expenseCategory),
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

        if ($movement->direction === MovementDirection::Debit) {
            JustificationPolicy::guardDebit($movement->account, $description);
        }

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

    private static function requireExpenseCategory(?ExpenseCategory $expenseCategory): ExpenseCategory
    {
        if ($expenseCategory === null) {
            throw new \InvalidArgumentException('An expense category must be chosen for this movement.');
        }

        return $expenseCategory;
    }

    private static function requireIncomeCategory(?IncomeCategory $incomeCategory): IncomeCategory
    {
        if ($incomeCategory === null) {
            throw new \InvalidArgumentException('An income category must be chosen for this movement.');
        }

        return $incomeCategory;
    }
}
