<?php

namespace App\UseCases\Financial;

use App\Domain\Enums\AccountType;
use App\Domain\Enums\FinancialMonthStatus;
use App\Domain\Enums\MovementCategory;
use App\Domain\Financial\ManualMovement;
use App\Models\FinancialMonth;
use App\Models\FinancialMovement;

/**
 * Lança um movimento manual no fechamento em aberto.
 *
 * A conta e a direção não são digitadas — são derivadas da categoria em
 * `ManualMovement` (a regra de domínio). Aqui fica só a orquestração: exigir um
 * `draft` corrente e persistir o movimento como manual (`is_generated = false`).
 */
class RecordMovementUseCase
{
    public function execute(
        MovementCategory $category,
        ?float $amount = null,
        ?float $quantity = null,
        ?float $unitPrice = null,
        ?AccountType $fund = null,
        ?string $description = null,
        ?string $occurredAt = null,
    ): FinancialMovement {
        $month = FinancialMonth::where('status', FinancialMonthStatus::Draft)->first();

        if ($month === null) {
            throw new \RuntimeException('There is no open financial month.');
        }

        $movement = ManualMovement::make($category, $amount, $quantity, $unitPrice, $fund);

        return $month->movements()->create([
            'account_type' => $movement->account,
            'direction' => $movement->direction,
            'category' => $movement->category,
            'amount' => $movement->amount,
            'quantity' => $movement->quantity,
            'unit_price' => $movement->unitPrice,
            'description' => $description,
            'occurred_at' => $occurredAt ?? now()->toDateString(),
            'is_generated' => false,
        ]);
    }
}
