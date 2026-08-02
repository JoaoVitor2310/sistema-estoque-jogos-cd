<?php

use App\Domain\Enums\AccountType;
use App\Domain\Enums\FinancialMonthStatus;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;
use App\Domain\Financial\FinancialMonthDefaults;
use App\Models\FinancialMonth;
use App\UseCases\Financial\BootstrapFinancialMonthData;
use App\UseCases\Financial\CreateDraftFinancialMonthUseCase;
use Tests\Support\FinancialMonthFactory;

describe('CreateDraftFinancialMonthUseCase', function () {

    it('creates a draft month with the provided percentages', function () {
        $month = app(CreateDraftFinancialMonthUseCase::class)->execute(new BootstrapFinancialMonthData(
            year: 2026,
            month: 7,
            reinvestmentPercent: 0.25,
            emergencyPercent: 0.15,
            partnerOneShare: 0.60,
        ));

        expect($month->status)->toBe(FinancialMonthStatus::Draft)
            ->and($month->year)->toBe(2026)
            ->and($month->month)->toBe(7)
            ->and((float) $month->reinvestment_percent)->toBe(0.25)
            ->and((float) $month->emergency_percent)->toBe(0.15)
            ->and((float) $month->partner_one_share)->toBe(0.60)
            ->and($month->closed_at)->toBeNull();
    });

    it('falls back to domain defaults for percentages when not provided', function () {
        $month = app(CreateDraftFinancialMonthUseCase::class)->execute(new BootstrapFinancialMonthData);

        expect((float) $month->reinvestment_percent)->toBe(FinancialMonthDefaults::REINVESTMENT_PERCENT)
            ->and((float) $month->emergency_percent)->toBe(FinancialMonthDefaults::EMERGENCY_PERCENT)
            ->and((float) $month->partner_one_share)->toBe(FinancialMonthDefaults::PARTNER_ONE_SHARE);
    });

    it('records opening credit movements for all four accounts', function () {
        $month = app(CreateDraftFinancialMonthUseCase::class)->execute(new BootstrapFinancialMonthData(
            openingBalances: [
                'principal' => 3200.00,
                'tf2' => 1500.00,
                'reinvestment' => 500.00,
                'emergency' => 0,
            ],
        ));

        $movements = $month->movements()->get();

        // Conta zerada não gera movimento.
        expect($movements)->toHaveCount(3);

        $movements->each(function ($movement) {
            expect($movement->category)->toBe(MovementCategory::Opening)
                ->and($movement->direction)->toBe(MovementDirection::Credit)
                ->and($movement->is_generated)->toBeFalse();
        });

        expect((float) $movements->firstWhere('account_type', AccountType::Principal)->amount)->toBe(3200.00)
            ->and((float) $movements->firstWhere('account_type', AccountType::Tf2)->amount)->toBe(1500.00)
            ->and((float) $movements->firstWhere('account_type', AccountType::Reinvestment)->amount)->toBe(500.00)
            ->and($movements->firstWhere('account_type', AccountType::Emergency))->toBeNull();
    });

    it('refuses to bootstrap again once any month exists', function () {
        app(CreateDraftFinancialMonthUseCase::class)->execute(new BootstrapFinancialMonthData(year: 2026, month: 7));

        expect(fn () => app(CreateDraftFinancialMonthUseCase::class)->execute(
            new BootstrapFinancialMonthData(year: 2026, month: 8)
        ))->toThrow(RuntimeException::class);

        expect(FinancialMonth::count())->toBe(1);
    });

    it('refuses to bootstrap when only a closed month exists', function () {
        FinancialMonthFactory::closed();

        expect(fn () => app(CreateDraftFinancialMonthUseCase::class)->execute(
            new BootstrapFinancialMonthData(year: 2026, month: 8)
        ))->toThrow(RuntimeException::class);
    });
});
