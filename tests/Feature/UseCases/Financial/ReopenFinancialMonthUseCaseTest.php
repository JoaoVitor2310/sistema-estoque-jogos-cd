<?php

use App\Domain\Enums\FinancialMonthStatus;
use App\Domain\Enums\MovementCategory;
use App\Models\FinancialMonth;
use App\UseCases\Financial\CloseMonthUseCase;
use App\UseCases\Financial\CreateDraftFinancialMonthUseCase;
use App\UseCases\Financial\RecordMovementUseCase;
use App\UseCases\Financial\ReopenFinancialMonthUseCase;

describe('ReopenFinancialMonthUseCase', function () {

    describe('with one closed month', function () {

        // Jul/2026 fechado (abertura Principal 1000, entrada 2000, saída 200);
        // o fechamento cria o draft de Ago/2026.
        beforeEach(function () {
            app(CreateDraftFinancialMonthUseCase::class)->execute([
                'year' => 2026,
                'month' => 7,
                'tf2TargetQuantity' => 10,
                'tf2Price' => 15.00,
                'openingBalances' => ['principal' => 1000.00],
            ]);
            app(RecordMovementUseCase::class)->execute(category: MovementCategory::Income, amount: 2000.00);
            app(RecordMovementUseCase::class)->execute(category: MovementCategory::Expense, amount: 200.00);
            app(CloseMonthUseCase::class)->execute();
        });

        it('returns the month to draft and clears the snapshot', function () {
            $july = FinancialMonth::where('month', 7)->first();

            app(ReopenFinancialMonthUseCase::class)->execute($july);
            $july->refresh();

            expect($july->status)->toBe(FinancialMonthStatus::Draft)
                ->and($july->closed_at)->toBeNull()
                ->and($july->total_income)->toBeNull()
                ->and($july->total_expenses)->toBeNull()
                ->and($july->tf2_reserve)->toBeNull()
                ->and($july->base_balance)->toBeNull()
                ->and($july->reinvestment_amount)->toBeNull()
                ->and($july->emergency_amount)->toBeNull()
                ->and($july->distributable)->toBeNull()
                ->and($july->partner_one_amount)->toBeNull()
                ->and($july->partner_two_amount)->toBeNull();
        });

        it('removes the generated movements but keeps the manual ones', function () {
            $july = FinancialMonth::where('month', 7)->first();

            app(ReopenFinancialMonthUseCase::class)->execute($july);
            $movements = $july->movements()->get();

            // Sobram só os 3 manuais: opening + income + expense.
            expect($movements->where('is_generated', true))->toHaveCount(0)
                ->and($movements)->toHaveCount(3)
                ->and($movements->firstWhere('category', MovementCategory::Opening))->not->toBeNull()
                ->and($movements->firstWhere('category', MovementCategory::Income))->not->toBeNull()
                ->and($movements->firstWhere('category', MovementCategory::Expense))->not->toBeNull()
                ->and($movements->firstWhere('category', MovementCategory::ReinvestmentTransfer))->toBeNull()
                ->and($movements->firstWhere('category', MovementCategory::PartnerDistribution))->toBeNull();
        });

        it('discards the draft that the close created, leaving a single draft', function () {
            $july = FinancialMonth::where('month', 7)->first();

            app(ReopenFinancialMonthUseCase::class)->execute($july);

            expect(FinancialMonth::where('status', FinancialMonthStatus::Draft)->count())->toBe(1)
                ->and(FinancialMonth::where('status', FinancialMonthStatus::Draft)->first()->month)->toBe(7)
                ->and(FinancialMonth::where('month', 8)->exists())->toBeFalse();
        });
    });

    it('throws when the month is not closed', function () {
        app(CreateDraftFinancialMonthUseCase::class)->execute([
            'year' => 2026,
            'month' => 7,
            'tf2TargetQuantity' => 0,
            'tf2Price' => 0,
        ]);
        $draft = FinancialMonth::where('status', FinancialMonthStatus::Draft)->first();

        expect(fn () => app(ReopenFinancialMonthUseCase::class)->execute($draft))
            ->toThrow(RuntimeException::class);
    });

    it('refuses to reopen a closed month that is not the most recent', function () {
        app(CreateDraftFinancialMonthUseCase::class)->execute([
            'year' => 2026,
            'month' => 7,
            'tf2TargetQuantity' => 0,
            'tf2Price' => 0,
        ]);
        app(CloseMonthUseCase::class)->execute(); // Jul fechado, Ago draft
        app(CloseMonthUseCase::class)->execute(); // Ago fechado, Set draft

        $july = FinancialMonth::where('month', 7)->first();
        expect(fn () => app(ReopenFinancialMonthUseCase::class)->execute($july))
            ->toThrow(RuntimeException::class);

        // O mais recente (Ago) pode ser reaberto.
        $august = FinancialMonth::where('month', 8)->first();
        app(ReopenFinancialMonthUseCase::class)->execute($august);

        expect($august->fresh()->status)->toBe(FinancialMonthStatus::Draft)
            ->and(FinancialMonth::where('month', 9)->exists())->toBeFalse();
    });
});
