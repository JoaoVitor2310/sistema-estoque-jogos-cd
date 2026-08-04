<?php

use App\Domain\Enums\AccountType;
use App\Domain\Enums\ExpenseCategory;
use App\Domain\Enums\IncomeCategory;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;
use App\Domain\Financial\ManualMovement;

describe('ManualMovement', function () {

    it('builds an income as a credit into the chosen account', function () {
        $movement = ManualMovement::make(
            MovementCategory::Income,
            AccountType::Principal,
            amount: 3257.03,
            incomeCategory: IncomeCategory::GamivoPayout,
        );

        expect($movement->account)->toBe(AccountType::Principal)
            ->and($movement->direction)->toBe(MovementDirection::Credit)
            ->and($movement->category)->toBe(MovementCategory::Income)
            ->and($movement->amount)->toBe(3257.03)
            ->and($movement->quantity)->toBeNull()
            ->and($movement->unitPrice)->toBeNull()
            ->and($movement->incomeCategory)->toBe(IncomeCategory::GamivoPayout)
            ->and($movement->expenseCategory)->toBeNull();
    });

    it('accepts an income into an account other than principal', function () {
        $movement = ManualMovement::make(
            MovementCategory::Income,
            AccountType::Emergency,
            amount: 500.00,
            incomeCategory: IncomeCategory::ExternalInvestment,
        );

        expect($movement->account)->toBe(AccountType::Emergency)
            ->and($movement->direction)->toBe(MovementDirection::Credit);
    });

    it('requires an income category for an income', function () {
        expect(fn () => ManualMovement::make(MovementCategory::Income, AccountType::Principal, amount: 100.00))
            ->toThrow(InvalidArgumentException::class);
    });

    it('builds an expense as a debit from the chosen account', function () {
        $movement = ManualMovement::make(
            MovementCategory::Expense,
            AccountType::Principal,
            amount: 207.05,
            expenseCategory: ExpenseCategory::Taxes,
        );

        expect($movement->account)->toBe(AccountType::Principal)
            ->and($movement->direction)->toBe(MovementDirection::Debit)
            ->and($movement->amount)->toBe(207.05)
            ->and($movement->expenseCategory)->toBe(ExpenseCategory::Taxes)
            ->and($movement->incomeCategory)->toBeNull();
    });

    it('requires an expense category for an expense', function () {
        expect(fn () => ManualMovement::make(MovementCategory::Expense, AccountType::Principal, amount: 207.05))
            ->toThrow(InvalidArgumentException::class);
    });

    it('builds a tf2 purchase debiting the tf2 budget', function () {
        $movement = ManualMovement::make(MovementCategory::Tf2Purchase, quantity: 100, unitPrice: 10.00);

        expect($movement->account)->toBe(AccountType::Tf2)
            ->and($movement->direction)->toBe(MovementDirection::Debit)
            ->and($movement->amount)->toBe(1000.00)
            ->and($movement->quantity)->toBe(100.0)
            ->and($movement->unitPrice)->toBe(10.00);
    });

    it('rounds the tf2 purchase amount half-up to the cent', function () {
        $movement = ManualMovement::make(MovementCategory::Tf2Purchase, quantity: 2.5, unitPrice: 10.13);

        // 2.5 × 10.13 = 25.325 → 25.33
        expect($movement->amount)->toBe(25.33);
    });

    it('requires a justification to debit a reserve fund', function () {
        expect(fn () => ManualMovement::make(
            MovementCategory::Expense,
            AccountType::Emergency,
            amount: 100.00,
            expenseCategory: ExpenseCategory::Other,
        ))->toThrow(InvalidArgumentException::class);

        expect(fn () => ManualMovement::make(
            MovementCategory::Expense,
            AccountType::Reinvestment,
            amount: 100.00,
            expenseCategory: ExpenseCategory::Other,
            description: '  ',
        ))->toThrow(InvalidArgumentException::class);
    });

    it('allows debiting a reserve fund when justified', function () {
        $movement = ManualMovement::make(
            MovementCategory::Expense,
            AccountType::Emergency,
            amount: 100.00,
            expenseCategory: ExpenseCategory::Other,
            description: 'Conserto emergencial',
        );

        expect($movement->account)->toBe(AccountType::Emergency)
            ->and($movement->direction)->toBe(MovementDirection::Debit);
    });

    it('does not require a justification to credit a reserve fund', function () {
        $movement = ManualMovement::make(
            MovementCategory::Income,
            AccountType::Emergency,
            amount: 100.00,
            incomeCategory: IncomeCategory::GamivoPayout,
        );

        expect($movement->direction)->toBe(MovementDirection::Credit);
    });

    it('requires an account for income and expense', function () {
        expect(fn () => ManualMovement::make(MovementCategory::Income, amount: 100.00, incomeCategory: IncomeCategory::GamivoPayout))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => ManualMovement::make(MovementCategory::Expense, amount: 100.00, expenseCategory: ExpenseCategory::Other))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects a non-positive amount', function () {
        expect(fn () => ManualMovement::make(
            MovementCategory::Income,
            AccountType::Principal,
            amount: 0,
            incomeCategory: IncomeCategory::GamivoPayout,
        ))->toThrow(InvalidArgumentException::class);
        expect(fn () => ManualMovement::make(
            MovementCategory::Expense,
            AccountType::Principal,
            amount: -5,
            expenseCategory: ExpenseCategory::Other,
        ))->toThrow(InvalidArgumentException::class);
    });

    it('rejects a tf2 purchase with non-positive quantity or price', function () {
        expect(fn () => ManualMovement::make(MovementCategory::Tf2Purchase, quantity: 0, unitPrice: 15.10))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => ManualMovement::make(MovementCategory::Tf2Purchase, quantity: 130, unitPrice: 0))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects categories that need their own use case', function () {
        // Transferência e distribuição geram mais de uma linha (group_id), e
        // abertura é gerada pelo sistema — nenhuma passa por aqui.
        expect(fn () => ManualMovement::make(MovementCategory::Transfer, AccountType::Principal, amount: 100))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => ManualMovement::make(MovementCategory::Tf2Allocation, quantity: 300, unitPrice: 10))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => ManualMovement::make(MovementCategory::PartnerDistribution, AccountType::Principal, amount: 100))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => ManualMovement::make(MovementCategory::Opening, AccountType::Principal, amount: 100))
            ->toThrow(InvalidArgumentException::class);
    });
});
