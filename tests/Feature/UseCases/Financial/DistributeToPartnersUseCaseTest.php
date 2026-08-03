<?php

use App\Domain\Enums\AccountType;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;
use App\Services\Financial\FinancialMonthService;
use App\UseCases\Financial\DistributeToPartnersUseCase;
use App\UseCases\Financial\DTO\DistributeToPartnersDTO;
use Tests\Support\FinancialMonthFactory;

describe('DistributeToPartnersUseCase', function () {

    it('records one debit per partner under one group', function () {
        $month = FinancialMonthFactory::draft();
        FinancialMonthFactory::credit($month, AccountType::Principal, 1000.00);

        $legs = app(DistributeToPartnersUseCase::class)->execute(new DistributeToPartnersDTO(
            source: AccountType::Principal,
            amount: 800.00,
            partnerOneShare: 0.50,
            occurredAt: '2026-07-28',
        ));

        expect($legs)->toHaveCount(2);

        foreach ($legs as $leg) {
            expect($leg->financial_month_id)->toBe($month->id)
                ->and($leg->account_type)->toBe(AccountType::Principal)
                ->and($leg->direction)->toBe(MovementDirection::Debit)
                ->and($leg->category)->toBe(MovementCategory::PartnerDistribution)
                ->and((float) $leg->amount)->toBe(400.00)
                ->and($leg->occurred_at->toDateString())->toBe('2026-07-28')
                ->and($leg->is_generated)->toBeFalse();
        }

        expect($legs[0]->partner_slot)->toBe(1)
            ->and($legs[1]->partner_slot)->toBe(2)
            ->and($legs[0]->group_id)->toBe($legs[1]->group_id);
    });

    it('takes the distributed money out of the company', function () {
        $month = FinancialMonthFactory::draft();
        FinancialMonthFactory::credit($month, AccountType::Principal, 1000.00);

        app(DistributeToPartnersUseCase::class)->execute(new DistributeToPartnersDTO(
            source: AccountType::Principal,
            amount: 800.00,
            partnerOneShare: 0.50,
        ));

        expect(app(FinancialMonthService::class)->accountBalances($month->fresh())['principal'])->toBe(200.00);
    });

    it('reconciles an uneven split, leaving the odd cent with partner one', function () {
        FinancialMonthFactory::draft();

        $legs = app(DistributeToPartnersUseCase::class)->execute(new DistributeToPartnersDTO(
            source: AccountType::Principal,
            amount: 100.01,
            partnerOneShare: 0.50,
        ));

        expect((float) $legs[0]->amount)->toBe(50.01)
            ->and((float) $legs[1]->amount)->toBe(50.00);
    });

    it('honours an uneven share', function () {
        FinancialMonthFactory::draft();

        $legs = app(DistributeToPartnersUseCase::class)->execute(new DistributeToPartnersDTO(
            source: AccountType::Principal,
            amount: 1000.00,
            partnerOneShare: 0.70,
        ));

        expect((float) $legs[0]->amount)->toBe(700.00)
            ->and((float) $legs[1]->amount)->toBe(300.00);
    });

    it('skips the empty side when one partner takes everything', function () {
        FinancialMonthFactory::draft();

        $legs = app(DistributeToPartnersUseCase::class)->execute(new DistributeToPartnersDTO(
            source: AccountType::Principal,
            amount: 500.00,
            partnerOneShare: 1.0,
        ));

        expect($legs)->toHaveCount(1)
            ->and($legs[0]->partner_slot)->toBe(1)
            ->and((float) $legs[0]->amount)->toBe(500.00);
    });

    it('refuses to distribute out of a reserve fund without a justification', function () {
        FinancialMonthFactory::draft();

        expect(fn () => app(DistributeToPartnersUseCase::class)->execute(new DistributeToPartnersDTO(
            source: AccountType::Emergency,
            amount: 500.00,
            partnerOneShare: 0.50,
        )))->toThrow(InvalidArgumentException::class);
    });

    it('refuses a non positive amount', function () {
        FinancialMonthFactory::draft();

        expect(fn () => app(DistributeToPartnersUseCase::class)->execute(new DistributeToPartnersDTO(
            source: AccountType::Principal,
            amount: 0,
            partnerOneShare: 0.50,
        )))->toThrow(InvalidArgumentException::class);
    });

    it('refuses a share outside 0 and 1', function () {
        FinancialMonthFactory::draft();

        expect(fn () => app(DistributeToPartnersUseCase::class)->execute(new DistributeToPartnersDTO(
            source: AccountType::Principal,
            amount: 500.00,
            partnerOneShare: 1.5,
        )))->toThrow(InvalidArgumentException::class);
    });

    it('throws when there is no open draft', function () {
        expect(fn () => app(DistributeToPartnersUseCase::class)->execute(new DistributeToPartnersDTO(
            source: AccountType::Principal,
            amount: 500.00,
            partnerOneShare: 0.50,
        )))->toThrow(RuntimeException::class);
    });
});
