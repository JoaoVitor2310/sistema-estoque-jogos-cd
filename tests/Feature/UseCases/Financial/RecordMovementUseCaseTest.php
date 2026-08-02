<?php

use App\Domain\Enums\AccountType;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;
use App\UseCases\Financial\RecordMovementData;
use App\UseCases\Financial\RecordMovementUseCase;
use Tests\Support\FinancialMonthFactory;

describe('RecordMovementUseCase', function () {

    it('records an income into the chosen account of the current draft', function () {
        $month = FinancialMonthFactory::draft();

        $movement = app(RecordMovementUseCase::class)->execute(new RecordMovementData(
            category: MovementCategory::Income,
            account: AccountType::Principal,
            amount: 3257.03,
            description: 'Saque da Gamivo',
            occurredAt: '2026-07-15',
        ));

        expect($movement->financial_month_id)->toBe($month->id)
            ->and($movement->account_type)->toBe(AccountType::Principal)
            ->and($movement->direction)->toBe(MovementDirection::Credit)
            ->and($movement->category)->toBe(MovementCategory::Income)
            ->and((float) $movement->amount)->toBe(3257.03)
            ->and($movement->description)->toBe('Saque da Gamivo')
            ->and($movement->occurred_at->toDateString())->toBe('2026-07-15')
            ->and($movement->is_generated)->toBeFalse();
    });

    it('records a tf2 purchase debiting the tf2 budget', function () {
        FinancialMonthFactory::draft();

        $movement = app(RecordMovementUseCase::class)->execute(new RecordMovementData(
            category: MovementCategory::Tf2Purchase,
            quantity: 100,
            unitPrice: 10.00,
        ));

        expect($movement->account_type)->toBe(AccountType::Tf2)
            ->and($movement->direction)->toBe(MovementDirection::Debit)
            ->and((float) $movement->amount)->toBe(1000.00)
            ->and((float) $movement->quantity)->toBe(100.0)
            ->and((float) $movement->unit_price)->toBe(10.00);
    });

    it('records a justified expense out of a reserve fund', function () {
        FinancialMonthFactory::draft();

        $movement = app(RecordMovementUseCase::class)->execute(new RecordMovementData(
            category: MovementCategory::Expense,
            account: AccountType::Emergency,
            amount: 200.00,
            description: 'Emergência médica',
        ));

        expect($movement->account_type)->toBe(AccountType::Emergency)
            ->and($movement->direction)->toBe(MovementDirection::Debit)
            ->and((float) $movement->amount)->toBe(200.00)
            ->and($movement->description)->toBe('Emergência médica');
    });

    it('refuses to debit a reserve fund without a justification', function () {
        FinancialMonthFactory::draft();

        expect(fn () => app(RecordMovementUseCase::class)->execute(new RecordMovementData(
            category: MovementCategory::Expense,
            account: AccountType::Emergency,
            amount: 200.00,
        )))->toThrow(InvalidArgumentException::class);
    });

    it('defaults occurred_at to today when not provided', function () {
        FinancialMonthFactory::draft();

        $movement = app(RecordMovementUseCase::class)->execute(new RecordMovementData(
            category: MovementCategory::Expense,
            account: AccountType::Principal,
            amount: 50.00,
        ));

        expect($movement->occurred_at->toDateString())->toBe(now()->toDateString());
    });

    it('throws when there is no open draft', function () {
        expect(fn () => app(RecordMovementUseCase::class)->execute(new RecordMovementData(
            category: MovementCategory::Income,
            account: AccountType::Principal,
            amount: 100.00,
        )))->toThrow(RuntimeException::class);
    });
});
