<?php

use App\Domain\Enums\AccountType;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;
use App\UseCases\Financial\DTO\RecordTransferDTO;
use App\UseCases\Financial\RecordTransferUseCase;
use Tests\Support\FinancialMonthFactory;

describe('RecordTransferUseCase', function () {

    it('records both legs of a transfer under one group', function () {
        $month = FinancialMonthFactory::draft();

        $legs = app(RecordTransferUseCase::class)->execute(new RecordTransferDTO(
            source: AccountType::Principal,
            destination: AccountType::Reinvestment,
            amount: 330.00,
            occurredAt: '2026-07-20',
        ));

        expect($legs)->toHaveCount(2);

        [$debit, $credit] = [$legs[0], $legs[1]];

        expect($debit->account_type)->toBe(AccountType::Principal)
            ->and($debit->direction)->toBe(MovementDirection::Debit)
            ->and($credit->account_type)->toBe(AccountType::Reinvestment)
            ->and($credit->direction)->toBe(MovementDirection::Credit);

        foreach ($legs as $leg) {
            expect($leg->financial_month_id)->toBe($month->id)
                ->and($leg->category)->toBe(MovementCategory::Transfer)
                ->and((float) $leg->amount)->toBe(330.00)
                ->and($leg->occurred_at->toDateString())->toBe('2026-07-20')
                ->and($leg->is_generated)->toBeFalse()
                ->and($leg->group_id)->not->toBeNull();
        }

        expect($legs[0]->group_id)->toBe($legs[1]->group_id);
    });

    it('leaves the company total untouched', function () {
        $month = FinancialMonthFactory::draft();
        FinancialMonthFactory::credit($month, AccountType::Principal, 1000.00);

        app(RecordTransferUseCase::class)->execute(new RecordTransferDTO(
            source: AccountType::Principal,
            destination: AccountType::Emergency,
            amount: 250.00,
        ));

        $balances = app(App\Services\Financial\FinancialMonthService::class)->accountBalances($month->fresh());

        expect($balances['principal'])->toBe(750.00)
            ->and($balances['emergency'])->toBe(250.00)
            ->and(array_sum($balances))->toBe(1000.00);
    });

    it('transfers a fraction of the current source balance', function () {
        $month = FinancialMonthFactory::draft();
        FinancialMonthFactory::credit($month, AccountType::Principal, 1650.00);

        $legs = app(RecordTransferUseCase::class)->execute(new RecordTransferDTO(
            source: AccountType::Principal,
            destination: AccountType::Reinvestment,
            fraction: 0.20,
        ));

        expect((float) $legs[0]->amount)->toBe(330.00);
    });

    it('applies the fraction over the balance left by earlier movements', function () {
        $month = FinancialMonthFactory::draft();
        FinancialMonthFactory::credit($month, AccountType::Principal, 2000.00);

        // Primeiro tira a verba de TF2; os 20% seguintes incidem sobre a sobra.
        app(RecordTransferUseCase::class)->execute(new RecordTransferDTO(
            source: AccountType::Principal,
            destination: AccountType::Tf2,
            amount: 1000.00,
        ));

        $legs = app(RecordTransferUseCase::class)->execute(new RecordTransferDTO(
            source: AccountType::Principal,
            destination: AccountType::Reinvestment,
            fraction: 0.20,
        ));

        expect((float) $legs[0]->amount)->toBe(200.00);
    });

    it('refuses to debit a reserve fund without a justification', function () {
        $month = FinancialMonthFactory::draft();
        FinancialMonthFactory::credit($month, AccountType::Emergency, 500.00);

        expect(fn () => app(RecordTransferUseCase::class)->execute(new RecordTransferDTO(
            source: AccountType::Emergency,
            destination: AccountType::Principal,
            amount: 100.00,
        )))->toThrow(InvalidArgumentException::class);
    });

    it('records a justified transfer out of a reserve fund', function () {
        $month = FinancialMonthFactory::draft();
        FinancialMonthFactory::credit($month, AccountType::Emergency, 500.00);

        $legs = app(RecordTransferUseCase::class)->execute(new RecordTransferDTO(
            source: AccountType::Emergency,
            destination: AccountType::Principal,
            amount: 100.00,
            description: 'Devolução do empréstimo ao caixa',
        ));

        expect($legs)->toHaveCount(2)
            ->and($legs[0]->description)->toBe('Devolução do empréstimo ao caixa');
    });

    it('refuses a transfer between the same account', function () {
        FinancialMonthFactory::draft();

        expect(fn () => app(RecordTransferUseCase::class)->execute(new RecordTransferDTO(
            source: AccountType::Principal,
            destination: AccountType::Principal,
            amount: 100.00,
        )))->toThrow(InvalidArgumentException::class);
    });

    it('refuses a transfer with neither an amount nor a fraction', function () {
        FinancialMonthFactory::draft();

        expect(fn () => app(RecordTransferUseCase::class)->execute(new RecordTransferDTO(
            source: AccountType::Principal,
            destination: AccountType::Reinvestment,
        )))->toThrow(InvalidArgumentException::class);
    });

    it('refuses a transfer carrying both an amount and a fraction', function () {
        FinancialMonthFactory::draft();

        expect(fn () => app(RecordTransferUseCase::class)->execute(new RecordTransferDTO(
            source: AccountType::Principal,
            destination: AccountType::Reinvestment,
            amount: 100.00,
            fraction: 0.20,
        )))->toThrow(InvalidArgumentException::class);
    });

    it('persists nothing when the transfer is refused', function () {
        $month = FinancialMonthFactory::draft();

        try {
            app(RecordTransferUseCase::class)->execute(new RecordTransferDTO(
                source: AccountType::Principal,
                destination: AccountType::Principal,
                amount: 100.00,
            ));
        } catch (InvalidArgumentException) {
            // esperado
        }

        expect($month->movements()->count())->toBe(0);
    });

    it('throws when there is no open draft', function () {
        expect(fn () => app(RecordTransferUseCase::class)->execute(new RecordTransferDTO(
            source: AccountType::Principal,
            destination: AccountType::Reinvestment,
            amount: 100.00,
        )))->toThrow(RuntimeException::class);
    });
});
