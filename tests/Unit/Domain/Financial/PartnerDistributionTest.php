<?php

/*
|--------------------------------------------------------------------------
| PartnerDistribution — saque dos dois sócios
|--------------------------------------------------------------------------
|
| PHP puro — sem DB, sem bootstrap do framework.
|
| Diferente de um transfer: aqui as duas pernas são DÉBITOS na mesma conta, uma
| por sócio, porque o dinheiro sai da empresa e não tem contrapartida em conta.
| A divisão em si (e o centavo órfão) é responsabilidade do PartnerSplit.
|
*/

use App\Domain\Enums\AccountType;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;
use App\Domain\Financial\PartnerDistribution;

describe('PartnerDistribution::from', function () {

    it('produces one debit per partner on the chosen account', function () {
        $legs = PartnerDistribution::from(AccountType::Principal, 1188.00, 0.50)->legs();

        expect($legs)->toHaveCount(2);

        foreach ($legs as $leg) {
            expect($leg->account)->toBe(AccountType::Principal)
                ->and($leg->direction)->toBe(MovementDirection::Debit)
                ->and($leg->category)->toBe(MovementCategory::PartnerDistribution)
                ->and($leg->amount)->toBe(594.00);
        }
    });

    it('tags each leg with the partner slot', function () {
        $legs = PartnerDistribution::from(AccountType::Principal, 1000.00, 0.70)->legs();

        expect($legs[0]->partnerSlot)->toBe(1)
            ->and($legs[0]->amount)->toBe(700.00)
            ->and($legs[1]->partnerSlot)->toBe(2)
            ->and($legs[1]->amount)->toBe(300.00);
    });

    it('gives the orphan cent to partner one', function () {
        $distribution = PartnerDistribution::from(AccountType::Principal, 0.01, 0.50);

        expect($distribution->split->partnerOne)->toBe(0.01)
            ->and($distribution->split->partnerTwo)->toBe(0.0);

        // Sobrando zero para o Sócio 2, só resta a perna do Sócio 1.
        $legs = $distribution->legs();
        expect($legs)->toHaveCount(1)
            ->and($legs[0]->partnerSlot)->toBe(1);
    });

    it('keeps the legs reconciled with the distributed total', function () {
        $legs = PartnerDistribution::from(AccountType::Principal, 999.99, 0.33)->legs();

        expect(round(array_sum(array_column($legs, 'amount')), 2))->toBe(999.99);
    });

    it('drops a leg worth nothing', function () {
        // Sócio 1 leva tudo: não faz sentido gravar um débito de R$ 0 para o Sócio 2.
        $legs = PartnerDistribution::from(AccountType::Principal, 500.00, 1.0)->legs();

        expect($legs)->toHaveCount(1)
            ->and($legs[0]->partnerSlot)->toBe(1)
            ->and($legs[0]->amount)->toBe(500.00);
    });

    it('requires a justification when paid out of a reserve fund', function () {
        expect(fn () => PartnerDistribution::from(AccountType::Emergency, 500.00, 0.50))
            ->toThrow(InvalidArgumentException::class);

        $justified = PartnerDistribution::from(AccountType::Emergency, 500.00, 0.50, 'Adiantamento');
        expect($justified->legs())->toHaveCount(2);
    });

    it('rejects a non-positive amount', function () {
        expect(fn () => PartnerDistribution::from(AccountType::Principal, 0, 0.50))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects a share outside 0..1', function () {
        expect(fn () => PartnerDistribution::from(AccountType::Principal, 100.00, 1.5))
            ->toThrow(InvalidArgumentException::class);
    });
});
