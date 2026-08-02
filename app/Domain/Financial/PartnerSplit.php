<?php

namespace App\Domain\Financial;

/**
 * Divide um valor entre os dois sócios.
 *
 * O Sócio 1 recebe a fração configurada, arredondada half-up ao centavo; o
 * Sócio 2 leva o **resto exato**. Isso garante que sócio 1 + sócio 2 sempre
 * reconcilie com o total distribuído — sem centavo sumindo nem sobrando — e o
 * centavo órfão de uma divisão ímpar fica com o Sócio 1.
 *
 * É o que sobrou da antiga cascata de fechamento (ver docs/adr/0005): a divisão
 * continua valendo, só mudou quem a dispara — hoje é um lançamento explícito.
 */
final class PartnerSplit
{
    private function __construct(
        /** Valor do Sócio 1, em reais (absorve o centavo órfão). */
        public readonly float $partnerOne,

        /** Valor do Sócio 2, em reais. */
        public readonly float $partnerTwo,
    ) {}

    /**
     * @param  float  $amount  Valor total a distribuir (R$)
     * @param  float  $partnerOneShare  Fração do Sócio 1 (ex: 0.50)
     */
    public static function divide(float $amount, float $partnerOneShare): self
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('The distributed amount must be positive.');
        }

        if ($partnerOneShare < 0 || $partnerOneShare > 1) {
            throw new \InvalidArgumentException("Partner one share must be between 0 and 1, got {$partnerOneShare}.");
        }

        $total = Money::fromReais($amount);
        $partnerOne = $total->percentage($partnerOneShare);

        return new self($partnerOne->toReais(), $total->minus($partnerOne)->toReais());
    }
}
