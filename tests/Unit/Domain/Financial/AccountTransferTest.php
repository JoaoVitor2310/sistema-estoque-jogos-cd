<?php

/*
|--------------------------------------------------------------------------
| AccountTransfer — movimentação entre duas contas
|--------------------------------------------------------------------------
|
| PHP puro — sem DB, sem bootstrap do framework.
|
| Um transfer sempre gera duas pernas (débito na origem, crédito no destino),
| que é o que mantém o total da empresa intacto. A alocação de verba de TF2 é
| um transfer nomeado: sempre Principal → Tf2, com qtd × preço registrados.
|
*/

use App\Domain\Enums\AccountType;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;
use App\Domain\Financial\AccountTransfer;

describe('AccountTransfer::between', function () {

    it('moves an absolute amount from source to destination', function () {
        $transfer = AccountTransfer::between(AccountType::Principal, AccountType::Reinvestment, 217.30);

        expect($transfer->source)->toBe(AccountType::Principal)
            ->and($transfer->destination)->toBe(AccountType::Reinvestment)
            ->and($transfer->amount)->toBe(217.30)
            ->and($transfer->category)->toBe(MovementCategory::Transfer)
            ->and($transfer->quantity)->toBeNull()
            ->and($transfer->unitPrice)->toBeNull();
    });

    it('exposes the two legs that keep the books balanced', function () {
        $legs = AccountTransfer::between(AccountType::Principal, AccountType::Emergency, 86.92)->legs();

        expect($legs)->toHaveCount(2)
            ->and($legs[0]->account)->toBe(AccountType::Principal)
            ->and($legs[0]->direction)->toBe(MovementDirection::Debit)
            ->and($legs[1]->account)->toBe(AccountType::Emergency)
            ->and($legs[1]->direction)->toBe(MovementDirection::Credit);

        foreach ($legs as $leg) {
            expect($leg->amount)->toBe(86.92);
        }
    });

    it('rejects a transfer to the same account', function () {
        expect(fn () => AccountTransfer::between(AccountType::Principal, AccountType::Principal, 100.00))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects a non-positive amount', function () {
        expect(fn () => AccountTransfer::between(AccountType::Principal, AccountType::Tf2, 0))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => AccountTransfer::between(AccountType::Principal, AccountType::Tf2, -5))
            ->toThrow(InvalidArgumentException::class);
    });

    it('requires a justification when the source is a reserve fund', function () {
        expect(fn () => AccountTransfer::between(AccountType::Emergency, AccountType::Principal, 100.00))
            ->toThrow(InvalidArgumentException::class);

        $justified = AccountTransfer::between(AccountType::Emergency, AccountType::Principal, 100.00, 'Cobrir caixa');
        expect($justified->amount)->toBe(100.00);
    });

    it('does not require a justification to feed a reserve fund', function () {
        $transfer = AccountTransfer::between(AccountType::Principal, AccountType::Emergency, 100.00);

        expect($transfer->destination)->toBe(AccountType::Emergency);
    });
});

describe('AccountTransfer::fractionOfBalance', function () {

    it('takes a percentage of the source balance', function () {
        // 20% de 1.086,48 = 217,296 → 217,30 (half-up)
        $transfer = AccountTransfer::fractionOfBalance(
            AccountType::Principal,
            AccountType::Reinvestment,
            sourceBalance: 1086.48,
            fraction: 0.20,
        );

        expect($transfer->amount)->toBe(217.30);
    });

    it('reproduces the old cascade when applied in order', function () {
        // Saldo base 1.086,48 → reinvest 20% = 217,30; sobra 869,18.
        $reinvest = AccountTransfer::fractionOfBalance(
            AccountType::Principal, AccountType::Reinvestment, 1086.48, 0.20
        );
        $afterReinvest = round(1086.48 - $reinvest->amount, 2);

        // Emergência incide sobre o saldo já reduzido: 10% de 869,18 = 86,92.
        $emergency = AccountTransfer::fractionOfBalance(
            AccountType::Principal, AccountType::Emergency, $afterReinvest, 0.10
        );

        expect($reinvest->amount)->toBe(217.30)
            ->and($emergency->amount)->toBe(86.92);
    });

    it('rejects a fraction outside 0..1', function () {
        expect(fn () => AccountTransfer::fractionOfBalance(AccountType::Principal, AccountType::Tf2, 1000.00, 1.5))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => AccountTransfer::fractionOfBalance(AccountType::Principal, AccountType::Tf2, 1000.00, -0.1))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects a fraction of a non-positive balance', function () {
        expect(fn () => AccountTransfer::fractionOfBalance(AccountType::Principal, AccountType::Tf2, 0, 0.20))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => AccountTransfer::fractionOfBalance(AccountType::Principal, AccountType::Tf2, -100, 0.20))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('AccountTransfer::tf2Allocation', function () {

    it('moves quantity times price from principal into the tf2 budget', function () {
        $allocation = AccountTransfer::tf2Allocation(quantity: 300, unitPrice: 10.00);

        expect($allocation->source)->toBe(AccountType::Principal)
            ->and($allocation->destination)->toBe(AccountType::Tf2)
            ->and($allocation->amount)->toBe(3000.00)
            ->and($allocation->category)->toBe(MovementCategory::Tf2Allocation)
            ->and($allocation->quantity)->toBe(300.0)
            ->and($allocation->unitPrice)->toBe(10.00);
    });

    it('carries quantity and price onto both legs', function () {
        $legs = AccountTransfer::tf2Allocation(quantity: 300, unitPrice: 10.00)->legs();

        foreach ($legs as $leg) {
            expect($leg->quantity)->toBe(300.0)
                ->and($leg->unitPrice)->toBe(10.00)
                ->and($leg->category)->toBe(MovementCategory::Tf2Allocation);
        }
    });

    it('rounds the allocation half-up to the cent', function () {
        // 2,5 × 10,13 = 25,325 → 25,33
        expect(AccountTransfer::tf2Allocation(quantity: 2.5, unitPrice: 10.13)->amount)->toBe(25.33);
    });

    it('rejects non-positive quantity or price', function () {
        expect(fn () => AccountTransfer::tf2Allocation(quantity: 0, unitPrice: 10.00))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => AccountTransfer::tf2Allocation(quantity: 300, unitPrice: 0))
            ->toThrow(InvalidArgumentException::class);
    });
});
