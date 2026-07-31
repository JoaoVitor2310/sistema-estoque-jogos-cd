<?php

use App\Domain\Enums\AccountType;
use App\Domain\Enums\FinancialMonthStatus;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;
use App\Domain\Financial\FinancialMonthDefaults;
use App\Models\FinancialMonth;
use App\UseCases\Financial\CreateDraftFinancialMonthUseCase;
use Illuminate\Support\Facades\DB;

describe('CreateDraftFinancialMonthUseCase', function () {

    it('creates a draft month with the provided inputs', function () {
        $month = app(CreateDraftFinancialMonthUseCase::class)->execute([
            'year' => 2026,
            'month' => 7,
            'tf2TargetQuantity' => 130,
            'tf2Price' => 15.10,
            'reinvestmentPercent' => 0.25,
            'emergencyPercent' => 0.15,
            'partnerOneShare' => 0.60,
            'partnerOneName' => 'Carca',
            'partnerTwoName' => 'Sócio',
        ]);

        expect($month->status)->toBe(FinancialMonthStatus::Draft)
            ->and($month->year)->toBe(2026)
            ->and($month->month)->toBe(7)
            ->and($month->tf2_target_quantity)->toBe(130)
            ->and($month->tf2_increment)->toBe(FinancialMonthDefaults::TF2_MONTHLY_INCREMENT)
            ->and((float) $month->tf2_price)->toBe(15.10)
            ->and((float) $month->reinvestment_percent)->toBe(0.25)
            ->and((float) $month->emergency_percent)->toBe(0.15)
            ->and((float) $month->partner_one_share)->toBe(0.60)
            ->and($month->partner_one_name)->toBe('Carca')
            ->and($month->partner_two_name)->toBe('Sócio')
            ->and($month->closed_at)->toBeNull();
    });

    it('falls back to domain defaults for rates when not provided', function () {
        $month = app(CreateDraftFinancialMonthUseCase::class)->execute([
            'year' => 2026,
            'month' => 7,
            'tf2TargetQuantity' => 0,
            'tf2Price' => 0,
        ]);

        expect((float) $month->reinvestment_percent)->toBe(FinancialMonthDefaults::REINVESTMENT_PERCENT)
            ->and((float) $month->emergency_percent)->toBe(FinancialMonthDefaults::EMERGENCY_PERCENT)
            ->and((float) $month->partner_one_share)->toBe(FinancialMonthDefaults::PARTNER_ONE_SHARE);
    });

    it('records opening credit movements for non-zero account balances', function () {
        $month = app(CreateDraftFinancialMonthUseCase::class)->execute([
            'year' => 2026,
            'month' => 7,
            'tf2TargetQuantity' => 0,
            'tf2Price' => 0,
            'openingBalances' => [
                'principal' => 3200.00,
                'reinvestment' => 500.00,
                'emergency' => 0,
            ],
        ]);

        $movements = $month->movements()->get();

        expect($movements)->toHaveCount(2);

        $movements->each(function ($movement) {
            expect($movement->category)->toBe(MovementCategory::Opening)
                ->and($movement->direction)->toBe(MovementDirection::Credit)
                ->and($movement->is_generated)->toBeFalse();
        });

        $principal = $movements->firstWhere('account_type', AccountType::Principal);
        $reinvestment = $movements->firstWhere('account_type', AccountType::Reinvestment);

        expect((float) $principal->amount)->toBe(3200.00)
            ->and((float) $reinvestment->amount)->toBe(500.00)
            ->and($movements->firstWhere('account_type', AccountType::Emergency))->toBeNull();
    });

    it('refuses to bootstrap again once any month exists', function () {
        app(CreateDraftFinancialMonthUseCase::class)->execute([
            'year' => 2026,
            'month' => 7,
            'tf2TargetQuantity' => 0,
            'tf2Price' => 0,
        ]);

        expect(fn () => app(CreateDraftFinancialMonthUseCase::class)->execute([
            'year' => 2026,
            'month' => 8,
            'tf2TargetQuantity' => 0,
            'tf2Price' => 0,
        ]))->toThrow(RuntimeException::class);

        expect(FinancialMonth::count())->toBe(1);
    });

    it('refuses to bootstrap when only a closed month exists', function () {
        DB::table('financial_months')->insert([
            'year' => 2026,
            'month' => 7,
            'status' => FinancialMonthStatus::Closed->value,
            'tf2_target_quantity' => 0,
            'tf2_increment' => 10,
            'tf2_price' => 0,
            'reinvestment_percent' => 0.20,
            'emergency_percent' => 0.10,
            'partner_one_share' => 0.50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => app(CreateDraftFinancialMonthUseCase::class)->execute([
            'year' => 2026,
            'month' => 8,
            'tf2TargetQuantity' => 0,
            'tf2Price' => 0,
        ]))->toThrow(RuntimeException::class);
    });
});
