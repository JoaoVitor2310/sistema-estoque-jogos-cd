<?php

/*
|--------------------------------------------------------------------------
| JustificationPolicy — quando um lançamento exige justificativa
|--------------------------------------------------------------------------
|
| PHP puro — sem DB, sem bootstrap do framework.
|
| A regra protege as reservas: tirar dinheiro do Reinvestimento ou da Emergência
| nunca pode ficar sem motivo registrado. Entrar dinheiro, não precisa.
|
*/

use App\Domain\Enums\AccountType;
use App\Domain\Financial\JustificationPolicy;

describe('JustificationPolicy::guardDebit', function () {

    it('requires a justification to debit a reserve fund', function () {
        foreach ([AccountType::Reinvestment, AccountType::Emergency] as $fund) {
            expect(fn () => JustificationPolicy::guardDebit($fund, null))
                ->toThrow(InvalidArgumentException::class);
        }
    });

    it('treats a blank justification as missing', function () {
        expect(fn () => JustificationPolicy::guardDebit(AccountType::Emergency, '   '))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => JustificationPolicy::guardDebit(AccountType::Emergency, ''))
            ->toThrow(InvalidArgumentException::class);
    });

    it('accepts a justified debit from a reserve fund', function () {
        JustificationPolicy::guardDebit(AccountType::Emergency, 'Conserto emergencial');
    })->throwsNoExceptions();

    it('does not require a justification outside the reserve funds', function () {
        JustificationPolicy::guardDebit(AccountType::Principal, null);
        JustificationPolicy::guardDebit(AccountType::Tf2, null);
    })->throwsNoExceptions();
});
