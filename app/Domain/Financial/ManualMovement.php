<?php

namespace App\Domain\Financial;

use App\Domain\Enums\AccountType;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;

/**
 * Um movimento manual lançado pelo usuário num fechamento em aberto.
 *
 * Concentra as regras que ligam a categoria à conta e à direção — a intenção do
 * usuário é "lançar uma entrada" / "lançar uma compra de TF2", e o resto é
 * derivado, não digitado:
 *
 *  - income          → Principal, crédito
 *  - expense         → Principal, débito
 *  - tf2_purchase    → Principal, débito; `amount = quantidade × preço`
 *  - fund_withdrawal → caixa escolhida (Reinvestment/Emergency), débito
 *
 * As categorias geradas pelo fechamento (transferências e distribuições) e a
 * `opening` (exclusiva do bootstrap) não são lançáveis por aqui — a fábrica as
 * recusa. Valores em reais, arredondados ao centavo (half-up) via `Money`.
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
        ?float $amount = null,
        ?float $quantity = null,
        ?float $unitPrice = null,
        ?AccountType $fund = null,
    ): self {
        return match ($category) {
            MovementCategory::Income => new self(
                AccountType::Principal,
                MovementDirection::Credit,
                $category,
                self::requirePositive($amount, 'amount'),
                null,
                null,
            ),
            MovementCategory::Expense => new self(
                AccountType::Principal,
                MovementDirection::Debit,
                $category,
                self::requirePositive($amount, 'amount'),
                null,
                null,
            ),
            MovementCategory::Tf2Purchase => self::tf2Purchase(
                self::requirePositive($quantity, 'quantity'),
                self::requirePositive($unitPrice, 'unitPrice'),
            ),
            MovementCategory::FundWithdrawal => self::fundWithdrawal(
                $fund,
                self::requirePositive($amount, 'amount'),
            ),
            default => throw new \InvalidArgumentException(
                "Category {$category->value} cannot be recorded as a manual movement."
            ),
        };
    }

    private static function tf2Purchase(float $quantity, float $unitPrice): self
    {
        return new self(
            AccountType::Principal,
            MovementDirection::Debit,
            MovementCategory::Tf2Purchase,
            Money::fromReais($quantity * $unitPrice)->toReais(),
            $quantity,
            $unitPrice,
        );
    }

    private static function fundWithdrawal(?AccountType $fund, float $amount): self
    {
        if ($fund === null || $fund === AccountType::Principal) {
            throw new \InvalidArgumentException(
                'A fund withdrawal requires a reinvestment or emergency account.'
            );
        }

        return new self($fund, MovementDirection::Debit, MovementCategory::FundWithdrawal, $amount, null, null);
    }

    private static function requirePositive(?float $value, string $field): float
    {
        if ($value === null || $value <= 0) {
            throw new \InvalidArgumentException("Field {$field} must be a positive value.");
        }

        return $value;
    }
}
