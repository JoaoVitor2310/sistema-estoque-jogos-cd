<?php

namespace App\Domain\Pricing\ValueObjects;

/**
 * Encapsula as taxas do Gamivo vindas do banco de dados.
 *
 * Imutável por design: uma vez criado, os valores não mudam.
 * Validação no construtor garante que valores inválidos nunca entrem no domínio.
 */
final class MarketplaceFee
{
    /**
     * @param  float  $percentualMenor  Taxa percentual para preços abaixo de €8 (ex: 0.06 = 6%)
     * @param  float  $fixoMenor  Taxa fixa para preços abaixo de €8 (ex: 0.25 = €0.25)
     * @param  float  $percentualMaior  Taxa percentual para preços a partir de €8 (ex: 0.08 = 8%)
     * @param  float  $fixoMaior  Taxa fixa para preços a partir de €8 (ex: 0.40 = €0.40)
     */
    public function __construct(
        public readonly float $percentualMenor,
        public readonly float $fixoMenor,
        public readonly float $percentualMaior,
        public readonly float $fixoMaior,
    ) {
        if ($percentualMenor < 0 || $percentualMenor > 1) {
            throw new \InvalidArgumentException("percentualMenor must be between 0 and 1, got {$percentualMenor}");
        }

        if ($percentualMaior < 0 || $percentualMaior > 1) {
            throw new \InvalidArgumentException("percentualMaior must be between 0 and 1, got {$percentualMaior}");
        }

        if ($fixoMenor < 0) {
            throw new \InvalidArgumentException("fixoMenor must be >= 0, got {$fixoMenor}");
        }

        if ($fixoMaior < 0) {
            throw new \InvalidArgumentException("fixoMaior must be >= 0, got {$fixoMaior}");
        }
    }

    /**
     * Constrói o VO a partir de um array associativo vindo do banco de dados.
     *
     * @param array{
     *     gamivoPercentualMenor: float,
     *     gamivoFixoMenor: float,
     *     gamivoPercentualMaior: float,
     *     gamivoFixoMaior: float
     * } $rates
     */
    public static function fromArray(array $rates): self
    {
        return new self(
            percentualMenor: (float) $rates['gamivoPercentualMenor'],
            fixoMenor: (float) $rates['gamivoFixoMenor'],
            percentualMaior: (float) $rates['gamivoPercentualMaior'],
            fixoMaior: (float) $rates['gamivoFixoMaior'],
        );
    }
}
