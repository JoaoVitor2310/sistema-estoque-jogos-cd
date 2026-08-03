<?php

use App\Domain\Enums\AccountType;
use App\Domain\Enums\FinancialMonthStatus;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;
use App\Models\FinancialMonth;
use App\Services\Financial\FinancialMonthService;
use App\UseCases\Financial\CloseMonthUseCase;
use App\UseCases\Financial\DTO\RecordTf2AllocationDTO;
use App\UseCases\Financial\RecordTf2AllocationUseCase;
use Tests\Support\FinancialMonthFactory;

/**
 * O fechamento não distribui nada (docs/adr/0005): só devolve a sobra da verba
 * de TF2 e abre o próximo mês. O que se verifica aqui é essa devolução, o
 * carry-forward uniforme e a virada de ano.
 */
function seedMonthWithBudget(): FinancialMonth
{
    $month = FinancialMonthFactory::draft();

    // Abertura: Principal 3.000. Verba de TF2: 300 × R$ 10 = 3.000 saem do
    // Principal e entram no Tf2 — pelo caminho real, não montado à mão.
    FinancialMonthFactory::credit($month, AccountType::Principal, 3000.00);

    app(RecordTf2AllocationUseCase::class)->execute(
        new RecordTf2AllocationDTO(quantity: 300, unitPrice: 10.00)
    );

    return $month;
}

describe('CloseMonthUseCase', function () {

    it('closes the month without distributing anything', function () {
        seedMonthWithBudget();

        $closed = app(CloseMonthUseCase::class)->execute();

        expect($closed->status)->toBe(FinancialMonthStatus::Closed)
            ->and($closed->closed_at)->not->toBeNull()
            ->and($closed->movements()->where('category', MovementCategory::PartnerDistribution)->exists())->toBeFalse();
    });

    describe('tf2 leftover', function () {

        it('returns the unspent budget to principal, zeroing the tf2 account', function () {
            $month = seedMonthWithBudget();

            // Comprou 100 das 300 previstas → sobram R$ 2.000.
            $month->movements()->create([
                'account_type' => AccountType::Tf2,
                'direction' => MovementDirection::Debit,
                'category' => MovementCategory::Tf2Purchase,
                'amount' => 1000.00,
                'quantity' => 100,
                'unit_price' => 10.00,
                'occurred_at' => now()->toDateString(),
                'is_generated' => false,
            ]);

            $closed = app(CloseMonthUseCase::class)->execute();
            $balances = app(FinancialMonthService::class)->accountBalances($closed->fresh());

            expect($balances['tf2'])->toBe(0.0)
                ->and($balances['principal'])->toBe(2000.00);

            $returns = $closed->movements()
                ->where('category', MovementCategory::Transfer)
                ->where('is_generated', true)
                ->get();

            expect($returns)->toHaveCount(2)
                ->and($returns->pluck('group_id')->unique())->toHaveCount(1)
                ->and((float) $returns->firstWhere('account_type', AccountType::Tf2)->amount)->toBe(2000.00)
                ->and($returns->firstWhere('account_type', AccountType::Tf2)->direction)->toBe(MovementDirection::Debit)
                ->and($returns->firstWhere('account_type', AccountType::Principal)->direction)->toBe(MovementDirection::Credit);
        });

        it('charges principal when the budget was overspent', function () {
            $month = seedMonthWithBudget();

            // Comprou 350 das 300 previstas → verba estourada em R$ 500.
            $month->movements()->create([
                'account_type' => AccountType::Tf2,
                'direction' => MovementDirection::Debit,
                'category' => MovementCategory::Tf2Purchase,
                'amount' => 3500.00,
                'quantity' => 350,
                'unit_price' => 10.00,
                'occurred_at' => now()->toDateString(),
                'is_generated' => false,
            ]);

            $closed = app(CloseMonthUseCase::class)->execute();
            $balances = app(FinancialMonthService::class)->accountBalances($closed->fresh());

            expect($balances['tf2'])->toBe(0.0)
                ->and($balances['principal'])->toBe(-500.00);

            $return = $closed->movements()
                ->where('category', MovementCategory::Transfer)
                ->where('account_type', AccountType::Principal)
                ->first();

            expect($return->direction)->toBe(MovementDirection::Debit)
                ->and((float) $return->amount)->toBe(500.00);
        });

        it('generates no movement when the budget landed exactly on zero', function () {
            $month = seedMonthWithBudget();

            $month->movements()->create([
                'account_type' => AccountType::Tf2,
                'direction' => MovementDirection::Debit,
                'category' => MovementCategory::Tf2Purchase,
                'amount' => 3000.00,
                'quantity' => 300,
                'unit_price' => 10.00,
                'occurred_at' => now()->toDateString(),
                'is_generated' => false,
            ]);

            $closed = app(CloseMonthUseCase::class)->execute();

            expect($closed->movements()->where('is_generated', true)->exists())->toBeFalse();
        });
    });

    describe('next draft', function () {

        it('opens the next draft carrying the percentages forward', function () {
            seedMonthWithBudget();

            app(CloseMonthUseCase::class)->execute();
            $next = FinancialMonth::where('status', FinancialMonthStatus::Draft)->first();

            expect($next)->not->toBeNull()
                ->and($next->year)->toBe(2026)
                ->and($next->month)->toBe(8)
                ->and((float) $next->reinvestment_percent)->toBe(0.20)
                ->and((float) $next->emergency_percent)->toBe(0.10)
                ->and((float) $next->partner_one_share)->toBe(0.50)
                ->and($next->closed_at)->toBeNull();
        });

        it('seeds the next draft with the closing balances, tf2 already zeroed', function () {
            seedMonthWithBudget();

            app(CloseMonthUseCase::class)->execute();
            $next = FinancialMonth::where('status', FinancialMonthStatus::Draft)->first();
            $openings = $next->movements()->where('category', MovementCategory::Opening)->get();

            // Só o Principal abre com saldo: a verba inteira voltou para ele.
            expect($openings)->toHaveCount(1)
                ->and($openings->first()->account_type)->toBe(AccountType::Principal)
                ->and((float) $openings->first()->amount)->toBe(3000.00)
                ->and($openings->first()->direction)->toBe(MovementDirection::Credit)
                ->and($openings->first()->is_generated)->toBeFalse();
        });

        it('rolls over December into the next year', function () {
            FinancialMonthFactory::draft(['year' => 2026, 'month' => 12]);

            app(CloseMonthUseCase::class)->execute();
            $next = FinancialMonth::where('status', FinancialMonthStatus::Draft)->first();

            expect($next->year)->toBe(2027)->and($next->month)->toBe(1);
        });
    });

    it('throws when there is no open draft', function () {
        expect(fn () => app(CloseMonthUseCase::class)->execute())
            ->toThrow(RuntimeException::class);
    });
});
