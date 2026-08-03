<?php

namespace App\Domain\Financial;

use App\Domain\Enums\AccountType;

/**
 * Quando um lançamento precisa dizer por quê.
 *
 * Tirar dinheiro de uma reserva (Reinvestimento ou Emergência) exige
 * justificativa — o histórico das caixinhas não pode ter saída sem motivo
 * registrado. É a proteção de auditoria que sobreviveu à remoção da categoria
 * `fund_withdrawal` (ver docs/adr/0005).
 *
 * A regra vale para qualquer débito numa reserva, independente da categoria:
 * uma saída, uma transferência de volta ao Principal ou um saque de sócio
 * tirado da caixinha. Entrada não precisa de motivo.
 */
final class JustificationPolicy
{
    public static function guardDebit(AccountType $account, ?string $description): void
    {
        if (! $account->isFund()) {
            return;
        }

        if ($description === null || trim($description) === '') {
            throw new \InvalidArgumentException('Debiting a reserve fund requires a justification.');
        }
    }
}
