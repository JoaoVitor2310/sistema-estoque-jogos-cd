<?php

use App\Domain\Enums\AccountType;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;
use App\Services\Financial\FinancialMonthService;
use App\UseCases\Financial\DTO\RecordTf2AllocationDTO;
use App\UseCases\Financial\RecordTf2AllocationUseCase;
use Tests\Support\FinancialMonthFactory;

describe('RecordTf2AllocationUseCase', function () {

    it('moves the tf2 budget from principal to the tf2 account', function () {
        $month = FinancialMonthFactory::draft();
        FinancialMonthFactory::credit($month, AccountType::Principal, 3000.00);

        $legs = app(RecordTf2AllocationUseCase::class)->execute(new RecordTf2AllocationDTO(
            quantity: 100,
            unitPrice: 10.50,
            occurredAt: '2026-07-05',
        ));

        expect($legs)->toHaveCount(2);

        [$debit, $credit] = [$legs[0], $legs[1]];

        expect($debit->account_type)->toBe(AccountType::Principal)
            ->and($debit->direction)->toBe(MovementDirection::Debit)
            ->and($credit->account_type)->toBe(AccountType::Tf2)
            ->and($credit->direction)->toBe(MovementDirection::Credit)
            ->and($debit->group_id)->toBe($credit->group_id);

        $balances = app(FinancialMonthService::class)->accountBalances($month->fresh());

        expect($balances['principal'])->toBe(1950.00)
            ->and($balances['tf2'])->toBe(1050.00);
    });

    it('keeps the target on the movement as quantity times unit price', function () {
        FinancialMonthFactory::draft();

        $legs = app(RecordTf2AllocationUseCase::class)->execute(new RecordTf2AllocationDTO(
            quantity: 100,
            unitPrice: 10.50,
        ));

        foreach ($legs as $leg) {
            expect($leg->category)->toBe(MovementCategory::Tf2Allocation)
                ->and((float) $leg->amount)->toBe(1050.00)
                ->and((float) $leg->quantity)->toBe(100.0)
                ->and((float) $leg->unit_price)->toBe(10.50)
                ->and($leg->is_generated)->toBeFalse();
        }
    });

    it('tops up the budget with a second allocation', function () {
        $month = FinancialMonthFactory::draft();

        app(RecordTf2AllocationUseCase::class)->execute(new RecordTf2AllocationDTO(quantity: 100, unitPrice: 10.00));
        app(RecordTf2AllocationUseCase::class)->execute(new RecordTf2AllocationDTO(quantity: 20, unitPrice: 11.00));

        expect(app(FinancialMonthService::class)->accountBalances($month->fresh())['tf2'])->toBe(1220.00);
    });

    it('refuses a non positive quantity or unit price', function (float $quantity, float $unitPrice) {
        FinancialMonthFactory::draft();

        expect(fn () => app(RecordTf2AllocationUseCase::class)->execute(
            new RecordTf2AllocationDTO(quantity: $quantity, unitPrice: $unitPrice)
        ))->toThrow(InvalidArgumentException::class);
    })->with([
        'zero quantity' => [0, 10.00],
        'negative quantity' => [-1, 10.00],
        'zero unit price' => [100, 0],
        'negative unit price' => [100, -10.00],
    ]);

    it('throws when there is no open draft', function () {
        expect(fn () => app(RecordTf2AllocationUseCase::class)->execute(
            new RecordTf2AllocationDTO(quantity: 100, unitPrice: 10.00)
        ))->toThrow(RuntimeException::class);
    });
});
