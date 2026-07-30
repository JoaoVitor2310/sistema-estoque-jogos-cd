<?php

namespace App\Domain\Enums;

/**
 * Direção de um movimento numa conta. O `amount` é sempre positivo; a direção
 * diz se soma (crédito/entrada) ou subtrai (débito/saída) do saldo.
 */
enum MovementDirection: string
{
    case Credit = 'credit';
    case Debit = 'debit';
}
