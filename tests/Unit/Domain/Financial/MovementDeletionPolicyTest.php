<?php

use App\Domain\Enums\FinancialMonthStatus;
use App\Domain\Enums\MovementCategory;
use App\Domain\Financial\MovementDeletionPolicy;

describe('MovementDeletionPolicy::guard', function () {

    it('allows deleting a manual movement of an open month', function (MovementCategory $category) {
        expect(fn () => MovementDeletionPolicy::guard(FinancialMonthStatus::Draft, false, $category))
            ->not->toThrow(Exception::class);
    })->with([
        'income' => MovementCategory::Income,
        'expense' => MovementCategory::Expense,
        'transfer' => MovementCategory::Transfer,
        'tf2 allocation' => MovementCategory::Tf2Allocation,
        'tf2 purchase' => MovementCategory::Tf2Purchase,
        'partner distribution' => MovementCategory::PartnerDistribution,
    ]);

    it('refuses to delete anything from a closed month', function () {
        expect(fn () => MovementDeletionPolicy::guard(FinancialMonthStatus::Closed, false, MovementCategory::Expense))
            ->toThrow(RuntimeException::class);
    });

    it('refuses to delete a generated movement', function () {
        expect(fn () => MovementDeletionPolicy::guard(FinancialMonthStatus::Draft, true, MovementCategory::Transfer))
            ->toThrow(InvalidArgumentException::class);
    });

    it('refuses to delete an opening balance even though it is not generated', function () {
        // A abertura nasce manual de propósito, para sobreviver a uma reabertura
        // do próprio mês — então `is_generated` sozinho não a protegeria.
        expect(fn () => MovementDeletionPolicy::guard(FinancialMonthStatus::Draft, false, MovementCategory::Opening))
            ->toThrow(InvalidArgumentException::class);
    });

    it('reports the closed month first when the movement is also generated', function () {
        expect(fn () => MovementDeletionPolicy::guard(FinancialMonthStatus::Closed, true, MovementCategory::Transfer))
            ->toThrow(RuntimeException::class);
    });
});
