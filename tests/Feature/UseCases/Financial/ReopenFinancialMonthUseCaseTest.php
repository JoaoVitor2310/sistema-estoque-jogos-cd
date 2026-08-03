<?php

use App\Domain\Enums\AccountType;
use App\Domain\Enums\FinancialMonthStatus;
use App\Domain\Enums\MovementCategory;
use App\Models\FinancialMonth;
use App\UseCases\Financial\CloseMonthUseCase;
use App\UseCases\Financial\CreateDraftFinancialMonthUseCase;
use App\UseCases\Financial\DTO\BootstrapFinancialMonthDTO;
use App\UseCases\Financial\DTO\RecordMovementDTO;
use App\UseCases\Financial\RecordMovementUseCase;
use App\UseCases\Financial\ReopenFinancialMonthUseCase;

describe('ReopenFinancialMonthUseCase', function () {

    describe('with one closed month', function () {

        // Jul/2026: abre com Principal 1.000, entra 2.000, sai 200 e sobra verba
        // de TF2 (que o fechamento devolve). Fechar cria o draft de Ago/2026.
        beforeEach(function () {
            app(CreateDraftFinancialMonthUseCase::class)->execute(new BootstrapFinancialMonthDTO(
                year: 2026,
                month: 7,
                openingBalances: ['principal' => 1000.00, 'tf2' => 500.00],
            ));
            app(RecordMovementUseCase::class)->execute(new RecordMovementDTO(
                category: MovementCategory::Income,
                account: AccountType::Principal,
                amount: 2000.00,
            ));
            app(RecordMovementUseCase::class)->execute(new RecordMovementDTO(
                category: MovementCategory::Expense,
                account: AccountType::Principal,
                amount: 200.00,
            ));
            app(CloseMonthUseCase::class)->execute();
        });

        it('returns the month to draft', function () {
            $july = FinancialMonth::where('month', 7)->first();

            app(ReopenFinancialMonthUseCase::class)->execute($july);

            expect($july->fresh()->status)->toBe(FinancialMonthStatus::Draft)
                ->and($july->fresh()->closed_at)->toBeNull();
        });

        it('undoes the tf2 return but keeps the manual movements', function () {
            $july = FinancialMonth::where('month', 7)->first();

            // Antes: 2 aberturas + entrada + saída + as 2 pernas da devolução.
            expect($july->movements()->where('is_generated', true)->count())->toBe(2);

            app(ReopenFinancialMonthUseCase::class)->execute($july);
            $movements = $july->movements()->get();

            expect($movements->where('is_generated', true))->toHaveCount(0)
                ->and($movements)->toHaveCount(4)
                ->and($movements->where('category', MovementCategory::Opening))->toHaveCount(2)
                ->and($movements->firstWhere('category', MovementCategory::Income))->not->toBeNull()
                ->and($movements->firstWhere('category', MovementCategory::Expense))->not->toBeNull()
                ->and($movements->firstWhere('category', MovementCategory::Transfer))->toBeNull();
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
        app(CreateDraftFinancialMonthUseCase::class)->execute(
            new BootstrapFinancialMonthDTO(year: 2026, month: 7)
        );
        $draft = FinancialMonth::where('status', FinancialMonthStatus::Draft)->first();

        expect(fn () => app(ReopenFinancialMonthUseCase::class)->execute($draft))
            ->toThrow(RuntimeException::class);
    });

    it('refuses to reopen a closed month that is not the most recent', function () {
        app(CreateDraftFinancialMonthUseCase::class)->execute(
            new BootstrapFinancialMonthDTO(year: 2026, month: 7)
        );
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
