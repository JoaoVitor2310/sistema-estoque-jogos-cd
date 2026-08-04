<?php

use App\Domain\Enums\AccountType;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;
use App\Services\Financial\FinancialMonthService;
use Tests\Support\FinancialMonthFactory;

describe('FinancialMonthService', function () {

    describe('overview()', function () {

        it('returns null current and balances when there is no draft', function () {
            $overview = app(FinancialMonthService::class)->overview();

            expect($overview['current'])->toBeNull()
                ->and($overview['balances'])->toBeNull()
                ->and($overview['closed'])->toBeEmpty();
        });

        it('returns the current draft with its balances', function () {
            $month = FinancialMonthFactory::draft();
            FinancialMonthFactory::credit($month, AccountType::Principal, 1000.00);

            $overview = app(FinancialMonthService::class)->overview();

            expect($overview['current']->id)->toBe($month->id)
                ->and($overview['balances']['principal'])->toBe(1000.00);
        });

        it('lists closed months from the most recent', function () {
            FinancialMonthFactory::closed(['year' => 2026, 'month' => 6]);
            FinancialMonthFactory::closed(['year' => 2026, 'month' => 7]);

            $closed = app(FinancialMonthService::class)->overview()['closed'];

            expect($closed)->toHaveCount(2)
                ->and($closed->first()->month)->toBe(7);
        });

        it('carries the tf2 prefill from the most recent closed month', function () {
            $older = FinancialMonthFactory::closed(['year' => 2026, 'month' => 6]);
            FinancialMonthFactory::allocateTf2($older, 100, 5.00);

            $recent = FinancialMonthFactory::closed(['year' => 2026, 'month' => 7]);
            FinancialMonthFactory::allocateTf2($recent, 300, 10.00);

            expect(app(FinancialMonthService::class)->overview()['tf2Prefill'])
                ->toBe(['quantity' => 300.0, 'unit_price' => 10.00]);
        });
    });

    describe('accountBalances()', function () {

        it('covers all four accounts, defaulting to zero', function () {
            $month = FinancialMonthFactory::draft();

            $balances = app(FinancialMonthService::class)->accountBalances($month);

            expect($balances)->toHaveKeys(['principal', 'tf2', 'reinvestment', 'emergency'])
                ->and($balances['principal'])->toBe(0.0)
                ->and($balances['tf2'])->toBe(0.0);
        });

        it('subtracts debits from credits per account', function () {
            $month = FinancialMonthFactory::draft();
            FinancialMonthFactory::credit($month, AccountType::Principal, 3000.00);
            FinancialMonthFactory::credit($month, AccountType::Tf2, 1000.00);

            $month->movements()->create([
                'account_type' => AccountType::Principal,
                'direction' => MovementDirection::Debit,
                'category' => MovementCategory::Expense,
                'amount' => 207.05,
                'occurred_at' => now()->toDateString(),
                'is_generated' => false,
            ]);

            $balances = app(FinancialMonthService::class)->accountBalances($month->fresh());

            expect($balances['principal'])->toBe(2792.95)
                ->and($balances['tf2'])->toBe(1000.00);
        });

        it('goes negative when an account is overdrawn', function () {
            $month = FinancialMonthFactory::draft();

            $month->movements()->create([
                'account_type' => AccountType::Tf2,
                'direction' => MovementDirection::Debit,
                'category' => MovementCategory::Tf2Purchase,
                'amount' => 500.00,
                'quantity' => 50,
                'unit_price' => 10.00,
                'occurred_at' => now()->toDateString(),
                'is_generated' => false,
            ]);

            expect(app(FinancialMonthService::class)->accountBalances($month)['tf2'])->toBe(-500.00);
        });
    });

    describe('tf2AllocationPrefill()', function () {

        it('returns nulls when there is no previous month', function () {
            expect(app(FinancialMonthService::class)->tf2AllocationPrefill(null))
                ->toBe(['quantity' => null, 'unit_price' => null]);
        });

        it('returns nulls when the previous month allocated nothing', function () {
            $previous = FinancialMonthFactory::closed();

            expect(app(FinancialMonthService::class)->tf2AllocationPrefill($previous))
                ->toBe(['quantity' => null, 'unit_price' => null]);
        });

        it('sums the allocated quantity and takes the last unit price', function () {
            $previous = FinancialMonthFactory::closed();
            FinancialMonthFactory::allocateTf2($previous, 300, 10.00);
            FinancialMonthFactory::allocateTf2($previous, 50, 11.50);

            expect(app(FinancialMonthService::class)->tf2AllocationPrefill($previous))
                ->toBe(['quantity' => 350.0, 'unit_price' => 11.50]);
        });

        it('counts each allocation once, not once per leg', function () {
            $previous = FinancialMonthFactory::closed();
            FinancialMonthFactory::allocateTf2($previous, 300, 10.00);

            // A alocação grava duas pernas com a mesma quantidade; somar as duas
            // devolveria 600.
            expect(app(FinancialMonthService::class)->tf2AllocationPrefill($previous)['quantity'])->toBe(300.0);
        });
    });
});
