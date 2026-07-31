<?php

use App\Domain\Enums\AccountType;
use App\Domain\Enums\FinancialMonthStatus;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;
use App\Models\FinancialMonth;
use App\UseCases\Financial\RecordMovementUseCase;
use Illuminate\Support\Facades\DB;

function draftMonth(): FinancialMonth
{
    $id = DB::table('financial_months')->insertGetId([
        'year' => 2026,
        'month' => 7,
        'status' => FinancialMonthStatus::Draft->value,
        'tf2_target_quantity' => 0,
        'tf2_increment' => 10,
        'tf2_price' => 0,
        'reinvestment_percent' => 0.20,
        'emergency_percent' => 0.10,
        'partner_one_share' => 0.50,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return FinancialMonth::findOrFail($id);
}

describe('RecordMovementUseCase', function () {

    it('records an income into the current draft', function () {
        $month = draftMonth();

        $movement = app(RecordMovementUseCase::class)->execute(
            category: MovementCategory::Income,
            amount: 3257.03,
            description: 'Faturamento Gamivo',
            occurredAt: '2026-07-15',
        );

        expect($movement->financial_month_id)->toBe($month->id)
            ->and($movement->account_type)->toBe(AccountType::Principal)
            ->and($movement->direction)->toBe(MovementDirection::Credit)
            ->and($movement->category)->toBe(MovementCategory::Income)
            ->and((float) $movement->amount)->toBe(3257.03)
            ->and($movement->description)->toBe('Faturamento Gamivo')
            ->and($movement->occurred_at->toDateString())->toBe('2026-07-15')
            ->and($movement->is_generated)->toBeFalse();
    });

    it('records a tf2 purchase with quantity, price and derived amount', function () {
        draftMonth();

        $movement = app(RecordMovementUseCase::class)->execute(
            category: MovementCategory::Tf2Purchase,
            quantity: 130,
            unitPrice: 15.10,
        );

        expect($movement->category)->toBe(MovementCategory::Tf2Purchase)
            ->and($movement->direction)->toBe(MovementDirection::Debit)
            ->and((float) $movement->amount)->toBe(1963.00)
            ->and((float) $movement->quantity)->toBe(130.0)
            ->and((float) $movement->unit_price)->toBe(15.10);
    });

    it('records a fund withdrawal from the chosen fund', function () {
        draftMonth();

        $movement = app(RecordMovementUseCase::class)->execute(
            category: MovementCategory::FundWithdrawal,
            amount: 200.00,
            fund: AccountType::Emergency,
            description: 'Emergência médica',
        );

        expect($movement->account_type)->toBe(AccountType::Emergency)
            ->and($movement->direction)->toBe(MovementDirection::Debit)
            ->and((float) $movement->amount)->toBe(200.00);
    });

    it('defaults occurred_at to today when not provided', function () {
        draftMonth();

        $movement = app(RecordMovementUseCase::class)->execute(
            category: MovementCategory::Expense,
            amount: 50.00,
        );

        expect($movement->occurred_at->toDateString())->toBe(now()->toDateString());
    });

    it('throws when there is no open draft', function () {
        expect(fn () => app(RecordMovementUseCase::class)->execute(
            category: MovementCategory::Income,
            amount: 100.00,
        ))->toThrow(RuntimeException::class);
    });
});
