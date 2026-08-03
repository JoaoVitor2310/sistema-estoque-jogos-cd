<?php

namespace App\UseCases\Financial\DTO;

/**
 * Entrada do bootstrap (primeiro fechamento). Carrega o estado de abertura vindo
 * do HTTP como um objeto tipado: os saldos iniciais das quatro contas e as
 * porcentagens que servirão de prefill nos lançamentos. O UseCase aplica os
 * defaults de domínio para o que vier nulo.
 *
 * Não há meta de TF2 aqui — ela vive no movimento `tf2_allocation`, lançado
 * durante o mês (ver docs/adr/0005).
 */
final class BootstrapFinancialMonthDTO
{
    /**
     * @param  array<string, float>  $openingBalances  saldo inicial por conta (principal/tf2/reinvestment/emergency)
     */
    public function __construct(
        public readonly ?int $year = null,
        public readonly ?int $month = null,
        public readonly ?float $reinvestmentPercent = null,
        public readonly ?float $emergencyPercent = null,
        public readonly ?float $partnerOneShare = null,
        public readonly array $openingBalances = [],
    ) {}
}
