<?php

use App\Domain\Enums\AccountType;
use App\Domain\Enums\FinancialMonthStatus;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;
use App\Models\FinancialMonth;
use App\UseCases\Financial\CloseMonthUseCase;
use App\UseCases\Financial\CreateDraftFinancialMonthUseCase;
use App\UseCases\Financial\RecordMovementUseCase;

/**
 * Cenário de números limpos (sem arredondamento) — o half-up já é coberto no
 * FinancialMonthCalculatorTest. Aqui verificamos a fiação: a cascata recebe os
 * inputs certos, os movimentos gerados batem e o próximo draft herda o estado.
 *
 * Abertura: Principal 1000. Entradas 2000, saídas op. 200.
 * Meta TF2 10 × 15,00 = 150 (reserva virtual).
 *   base        = 2000 − 150 − 200 = 1650
 *   reinvest    = 20% × 1650       = 330   → afterReinvest 1320
 *   emergency   = 10% × 1320       = 132   → distribuível 1188
 *   por sócio   = 1188 ÷ 2         = 594
 * Saldos de fecho: Principal 1000+2000−200−330−132−1188 = 1150 (retém a reserva),
 *                  Reinvestment 330, Emergency 132.
 */
function seedClosableDraft(): void
{
    app(CreateDraftFinancialMonthUseCase::class)->execute([
        'year' => 2026,
        'month' => 7,
        'tf2TargetQuantity' => 10,
        'tf2Price' => 15.00,
        'partnerOneName' => 'Carca',
        'partnerTwoName' => 'Sócio',
        'openingBalances' => ['principal' => 1000.00],
    ]);
    app(RecordMovementUseCase::class)->execute(category: MovementCategory::Income, amount: 2000.00);
    app(RecordMovementUseCase::class)->execute(category: MovementCategory::Expense, amount: 200.00);
}

describe('CloseMonthUseCase', function () {

    describe('with a closable draft', function () {

        beforeEach(fn () => seedClosableDraft());

        it('freezes the cascade snapshot and closes the month', function () {
            $closed = app(CloseMonthUseCase::class)->execute();

            expect($closed->status)->toBe(FinancialMonthStatus::Closed)
                ->and($closed->closed_at)->not->toBeNull()
                ->and((float) $closed->total_income)->toBe(2000.00)
                ->and((float) $closed->total_expenses)->toBe(200.00)
                ->and((float) $closed->tf2_reserve)->toBe(150.00)
                ->and((float) $closed->base_balance)->toBe(1650.00)
                ->and((float) $closed->reinvestment_amount)->toBe(330.00)
                ->and((float) $closed->emergency_amount)->toBe(132.00)
                ->and((float) $closed->distributable)->toBe(1188.00)
                ->and((float) $closed->partner_one_amount)->toBe(594.00)
                ->and((float) $closed->partner_two_amount)->toBe(594.00);
        });

        it('generates the transfer and distribution movements (double-entry)', function () {
            $closed = app(CloseMonthUseCase::class)->execute();
            $generated = $closed->movements()->where('is_generated', true)->get();

            // 2 transferências (2 lados cada) + 2 distribuições
            expect($generated)->toHaveCount(6);

            $reinvestOut = $generated->first(fn ($m) => $m->category === MovementCategory::ReinvestmentTransfer && $m->account_type === AccountType::Principal);
            $reinvestIn = $generated->first(fn ($m) => $m->category === MovementCategory::ReinvestmentTransfer && $m->account_type === AccountType::Reinvestment);
            expect($reinvestOut->direction)->toBe(MovementDirection::Debit)
                ->and((float) $reinvestOut->amount)->toBe(330.00)
                ->and($reinvestIn->direction)->toBe(MovementDirection::Credit)
                ->and((float) $reinvestIn->amount)->toBe(330.00);

            $emergencyOut = $generated->first(fn ($m) => $m->category === MovementCategory::EmergencyTransfer && $m->account_type === AccountType::Principal);
            $emergencyIn = $generated->first(fn ($m) => $m->category === MovementCategory::EmergencyTransfer && $m->account_type === AccountType::Emergency);
            expect((float) $emergencyOut->amount)->toBe(132.00)
                ->and((float) $emergencyIn->amount)->toBe(132.00);

            $distributions = $generated->where('category', MovementCategory::PartnerDistribution);
            expect($distributions)->toHaveCount(2);
            $distributions->each(function ($m) {
                expect($m->account_type)->toBe(AccountType::Principal)
                    ->and($m->direction)->toBe(MovementDirection::Debit)
                    ->and((float) $m->amount)->toBe(594.00);
            });
        });

        it('does not create a movement for the virtual tf2 reserve', function () {
            $closed = app(CloseMonthUseCase::class)->execute();

            expect($closed->movements()->where('category', 'tf2_reserve')->exists())->toBeFalse()
                ->and($closed->movements()->where('description', 'like', '%reserva%')->exists())->toBeFalse();
        });

        it('opens the next draft carrying state forward with tf2 target +increment', function () {
            app(CloseMonthUseCase::class)->execute();
            $next = FinancialMonth::where('status', FinancialMonthStatus::Draft)->first();

            expect($next)->not->toBeNull()
                ->and($next->year)->toBe(2026)
                ->and($next->month)->toBe(8)
                ->and($next->tf2_target_quantity)->toBe(20) // 10 + increment 10
                ->and((float) $next->tf2_price)->toBe(15.00)
                ->and((float) $next->reinvestment_percent)->toBe(0.20)
                ->and((float) $next->emergency_percent)->toBe(0.10)
                ->and((float) $next->partner_one_share)->toBe(0.50)
                ->and($next->partner_one_name)->toBe('Carca')
                ->and($next->partner_two_name)->toBe('Sócio')
                ->and($next->closed_at)->toBeNull();
        });

        it('seeds the next draft with opening balances equal to the closing balances', function () {
            app(CloseMonthUseCase::class)->execute();
            $next = FinancialMonth::where('status', FinancialMonthStatus::Draft)->first();
            $openings = $next->movements()->where('category', MovementCategory::Opening)->get();

            expect($openings)->toHaveCount(3);
            $openings->each(fn ($m) => expect($m->direction)->toBe(MovementDirection::Credit)->and($m->is_generated)->toBeFalse());

            expect((float) $openings->firstWhere('account_type', AccountType::Principal)->amount)->toBe(1150.00)
                ->and((float) $openings->firstWhere('account_type', AccountType::Reinvestment)->amount)->toBe(330.00)
                ->and((float) $openings->firstWhere('account_type', AccountType::Emergency)->amount)->toBe(132.00);
        });
    });

    it('rolls over December into the next year', function () {
        app(CreateDraftFinancialMonthUseCase::class)->execute([
            'year' => 2026,
            'month' => 12,
            'tf2TargetQuantity' => 0,
            'tf2Price' => 0,
        ]);

        app(CloseMonthUseCase::class)->execute();
        $next = FinancialMonth::where('status', FinancialMonthStatus::Draft)->first();

        expect($next->year)->toBe(2027)->and($next->month)->toBe(1);
    });

    it('throws when there is no open draft', function () {
        expect(fn () => app(CloseMonthUseCase::class)->execute())
            ->toThrow(RuntimeException::class);
    });
});
