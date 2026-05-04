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
     * @param  float  $percentLow  Taxa percentual para preços abaixo de €8 (ex: 0.06 = 6%)
     * @param  float  $fixedLow  Taxa fixa para preços abaixo de €8 (ex: 0.25 = €0.25)
     * @param  float  $percentHigh  Taxa percentual para preços a partir de €8 (ex: 0.08 = 8%)
     * @param  float  $fixedHigh  Taxa fixa para preços a partir de €8 (ex: 0.40 = €0.40)
     */
    public function __construct(
        public readonly float $percentLow,
        public readonly float $fixedLow,
        public readonly float $percentHigh,
        public readonly float $fixedHigh,
    ) {
        if ($percentLow < 0 || $percentLow > 1) {
            throw new \InvalidArgumentException("percentLow must be between 0 and 1, got {$percentLow}");
        }

        if ($percentHigh < 0 || $percentHigh > 1) {
            throw new \InvalidArgumentException("percentHigh must be between 0 and 1, got {$percentHigh}");
        }

        if ($fixedLow < 0) {
            throw new \InvalidArgumentException("fixedLow must be >= 0, got {$fixedLow}");
        }

        if ($fixedHigh < 0) {
            throw new \InvalidArgumentException("fixedHigh must be >= 0, got {$fixedHigh}");
        }
    }

    /**
     * Constrói o VO a partir de um array associativo vindo do banco de dados.
     *
     * @param array{
     *     gamivo_percent_low: float,
     *     gamivo_fixed_low: float,
     *     gamivo_percent_high: float,
     *     gamivo_fixed_high: float
     * } $rates
     */
    public static function fromArray(array $rates): self
    {
        return new self(
            percentLow: (float) $rates['gamivo_percent_low'],
            fixedLow: (float) $rates['gamivo_fixed_low'],
            percentHigh: (float) $rates['gamivo_percent_high'],
            fixedHigh: (float) $rates['gamivo_fixed_high'],
        );
    }
}
