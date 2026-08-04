<?php

use App\Domain\Enums\AccountType;
use App\Domain\Enums\IncomeCategory;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;
use App\Models\FinancialMovement;
use App\Services\Financial\FinancialMonthService;
use App\UseCases\Financial\DeleteMovementGroupUseCase;
use App\UseCases\Financial\DistributeToPartnersUseCase;
use App\UseCases\Financial\DTO\DistributeToPartnersDTO;
use App\UseCases\Financial\DTO\RecordMovementDTO;
use App\UseCases\Financial\DTO\RecordTransferDTO;
use App\UseCases\Financial\RecordMovementUseCase;
use App\UseCases\Financial\RecordTransferUseCase;
use Tests\Support\FinancialMonthFactory;

describe('DeleteMovementGroupUseCase', function () {

    it('deletes both legs of a transfer from either side', function () {
        $month = FinancialMonthFactory::draft();
        FinancialMonthFactory::credit($month, AccountType::Principal, 1000.00);

        $legs = app(RecordTransferUseCase::class)->execute(new RecordTransferDTO(
            source: AccountType::Principal,
            destination: AccountType::Reinvestment,
            amount: 300.00,
        ));

        // Clica na perna de crédito; o débito do outro lado tem de sumir junto.
        $deleted = app(DeleteMovementGroupUseCase::class)->execute($legs[1]);

        expect($deleted)->toBe(2)
            ->and(FinancialMovement::where('category', MovementCategory::Transfer)->count())->toBe(0);
    });

    it('puts the money back exactly where it was', function () {
        $month = FinancialMonthFactory::draft();
        FinancialMonthFactory::credit($month, AccountType::Principal, 1000.00);

        $legs = app(RecordTransferUseCase::class)->execute(new RecordTransferDTO(
            source: AccountType::Principal,
            destination: AccountType::Emergency,
            amount: 300.00,
        ));

        app(DeleteMovementGroupUseCase::class)->execute($legs[0]);

        $balances = app(FinancialMonthService::class)->accountBalances($month->fresh());

        expect($balances['principal'])->toBe(1000.00)
            ->and($balances['emergency'])->toBe(0.0);
    });

    it('deletes both partner debits of a distribution', function () {
        FinancialMonthFactory::draft();

        $legs = app(DistributeToPartnersUseCase::class)->execute(new DistributeToPartnersDTO(
            source: AccountType::Principal,
            amount: 800.00,
            partnerOneShare: 0.50,
        ));

        $deleted = app(DeleteMovementGroupUseCase::class)->execute($legs[0]);

        expect($deleted)->toBe(2)
            ->and(FinancialMovement::where('category', MovementCategory::PartnerDistribution)->count())->toBe(0);
    });

    it('deletes a simple movement that has no group', function () {
        $month = FinancialMonthFactory::draft();

        $movement = app(RecordMovementUseCase::class)->execute(new RecordMovementDTO(
            category: MovementCategory::Income,
            account: AccountType::Principal,
            amount: 500.00,
            incomeCategory: IncomeCategory::GamivoPayout,
        ));

        expect($movement->group_id)->toBeNull();

        $deleted = app(DeleteMovementGroupUseCase::class)->execute($movement);

        expect($deleted)->toBe(1)
            ->and($month->movements()->count())->toBe(0);
    });

    it('leaves other entries untouched', function () {
        $month = FinancialMonthFactory::draft();

        $kept = app(RecordMovementUseCase::class)->execute(new RecordMovementDTO(
            category: MovementCategory::Income,
            account: AccountType::Principal,
            amount: 500.00,
            incomeCategory: IncomeCategory::GamivoPayout,
        ));

        $firstTransfer = app(RecordTransferUseCase::class)->execute(new RecordTransferDTO(
            source: AccountType::Principal,
            destination: AccountType::Reinvestment,
            amount: 100.00,
        ));

        $secondTransfer = app(RecordTransferUseCase::class)->execute(new RecordTransferDTO(
            source: AccountType::Principal,
            destination: AccountType::Emergency,
            amount: 200.00,
        ));

        app(DeleteMovementGroupUseCase::class)->execute($firstTransfer[0]);

        expect($month->movements()->count())->toBe(3)
            ->and(FinancialMovement::find($kept->id))->not->toBeNull()
            ->and(FinancialMovement::find($secondTransfer[0]->id))->not->toBeNull()
            ->and(FinancialMovement::find($secondTransfer[1]->id))->not->toBeNull();
    });

    it('refuses to delete from a closed month, keeping every line', function () {
        $month = FinancialMonthFactory::closed();
        FinancialMonthFactory::credit($month, AccountType::Principal, 1000.00);

        $movement = $month->movements()->create([
            'account_type' => AccountType::Principal,
            'direction' => MovementDirection::Debit,
            'category' => MovementCategory::Expense,
            'amount' => 50.00,
            'occurred_at' => now()->toDateString(),
            'is_generated' => false,
        ]);

        expect(fn () => app(DeleteMovementGroupUseCase::class)->execute($movement))
            ->toThrow(RuntimeException::class);

        expect($month->movements()->count())->toBe(2);
    });

    it('refuses to delete an opening balance, which carries the previous month forward', function () {
        $month = FinancialMonthFactory::draft();
        FinancialMonthFactory::credit($month, AccountType::Principal, 1000.00);

        $opening = $month->movements()->firstWhere('category', MovementCategory::Opening);

        // Nasce manual de propósito, então a barreira aqui é a categoria.
        expect($opening->is_generated)->toBeFalse();

        expect(fn () => app(DeleteMovementGroupUseCase::class)->execute($opening))
            ->toThrow(InvalidArgumentException::class);

        expect($month->movements()->count())->toBe(1);
    });

    it('refuses to delete a generated movement, since reopening undoes those', function () {
        $month = FinancialMonthFactory::draft();

        $opening = $month->movements()->create([
            'account_type' => AccountType::Principal,
            'direction' => MovementDirection::Credit,
            'category' => MovementCategory::Opening,
            'amount' => 1000.00,
            'occurred_at' => now()->toDateString(),
            'is_generated' => true,
        ]);

        expect(fn () => app(DeleteMovementGroupUseCase::class)->execute($opening))
            ->toThrow(InvalidArgumentException::class);

        expect($month->movements()->count())->toBe(1);
    });
});
