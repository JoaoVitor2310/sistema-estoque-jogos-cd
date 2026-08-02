<?php

namespace App\Domain\Enums;

/**
 * As quatro contas do domínio financeiro. Saldo de cada uma é derivado da soma
 * dos movimentos (não há coluna de saldo persistida).
 *
 *  - Principal      : caixa operacional (faturamento entra; verba de TF2,
 *                     despesas, transferências e distribuições saem)
 *  - Tf2            : verba do mês para comprar TF2 keys (recebe a alocação do
 *                     Principal; as compras reais debitam daqui). A sobra volta
 *                     ao Principal no fechamento — ver docs/adr/0005
 *  - Reinvestment   : caixinha de reinvestimento
 *  - Emergency      : caixinha de emergência
 *
 * Débito em Reinvestment ou Emergency exige justificativa.
 */
enum AccountType: string
{
    case Principal = 'principal';
    case Tf2 = 'tf2';
    case Reinvestment = 'reinvestment';
    case Emergency = 'emergency';

    /**
     * Caixinhas de reserva — mexer nelas exige justificativa.
     */
    public function isFund(): bool
    {
        return $this === self::Reinvestment || $this === self::Emergency;
    }
}
