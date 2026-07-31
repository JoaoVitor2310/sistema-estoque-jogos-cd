<?php

namespace App\Domain\Financial;

/**
 * Resultado da cascata de fechamento mensal (FinancialMonthCalculator).
 *
 * Todos os valores em reais (R$), já arredondados a 2 casas. Imutável por
 * design: campos readonly. Os campos espelham as linhas do waterfall visual,
 * na ordem em que aparecem na tela.
 */
final class FinancialMonthResult
{
    public function __construct(
        /** Reserva TF2 (meta × preço) — earmark virtual, não sai do Principal. */
        public readonly float $tf2Reserve,

        /** Saldo após entradas − reserva − saídas operacionais. */
        public readonly float $base,

        /** Depósito na caixa de Reinvestimento (percentual do saldo base). */
        public readonly float $reinvestment,

        /** Saldo após o depósito de reinvestimento. */
        public readonly float $afterReinvestment,

        /** Depósito na caixa de Emergência (percentual do saldo pós-reinvest). */
        public readonly float $emergency,

        /** Saldo distribuível aos sócios. */
        public readonly float $distributable,

        /** Valor do Sócio 1 (absorve o centavo órfão em divisão ímpar). */
        public readonly float $partnerOne,

        /** Valor do Sócio 2. */
        public readonly float $partnerTwo,
    ) {}
}
