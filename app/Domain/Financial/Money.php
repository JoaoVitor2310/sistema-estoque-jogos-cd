<?php

namespace App\Domain\Financial;

/**
 * Valor monetário em reais, representado internamente em **centavos inteiros**.
 *
 * O domínio financeiro exige arredondamento half-up previsível e reconciliação
 * exata (a soma das partes bate com o todo, sem centavo sumindo). Float quebra
 * isso em .5 — round(50.005, 2) devolve 50.00 porque 50.005 não é representável
 * em binário. Trabalhar em centavos inteiros elimina o problema; reais (float)
 * só entram por `fromReais` e saem por `toReais`, nas fronteiras.
 *
 * Imutável: toda operação devolve uma nova instância.
 */
final class Money
{
    private function __construct(public readonly int $cents) {}

    public static function fromReais(float $reais): self
    {
        return new self((int) round($reais * 100));
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function plus(Money $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function minus(Money $other): self
    {
        return new self($this->cents - $other->cents);
    }

    /**
     * Fração do valor, arredondada half-up ao centavo (ex: 0.20 = 20%).
     */
    public function percentage(float $fraction): self
    {
        return new self((int) round($this->cents * $fraction));
    }

    public function toReais(): float
    {
        return $this->cents / 100;
    }
}
