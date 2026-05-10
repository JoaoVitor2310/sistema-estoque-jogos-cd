<?php

namespace App\Domain\Pricing;

/**
 * Calcula os preços mínimo e máximo que a API do Gamivo pode atingir
 * ao ajustar automaticamente o preço de uma key.
 *
 * Tiers do mínimo:
 *   valorPago > 10  → valorPago × 1.4  (+40%)
 *   valorPago > 4   → valorPago × 1.5  (+50%)
 *   demais          → valorPago × 1.6  (+60%)
 *
 * Tiers do máximo:
 *   valorPago < 1        → valorPago × 30
 *   valorPago >= 1       → valorPago × 8
 *   precoCliente >= max  → precoCliente × 8 (override: mercado já ultrapassou o teto)
 *
 * Piso de 0.02 aplicado a ambos os valores.
 */
final class MinMaxPriceCalculator
{
    /** Piso absoluto de seller_price (guard-rail inferior). */
    public const FLOOR = 0.02;

    /** Teto absoluto de seller_price (guard-rail superior). */
    public const CEILING = 500.0;

    // --- Tiers do mínimo ---

    /** Custo acima deste valor aplica multiplicador MIN_MULTIPLIER_ABOVE_10. */
    public const MIN_COST_THRESHOLD_HIGH = 10;

    /** Custo acima deste valor aplica multiplicador MIN_MULTIPLIER_ABOVE_4. */
    public const MIN_COST_THRESHOLD_MID = 4;

    /** Multiplicador do mínimo quando custo > 10 (+40%). */
    public const MIN_MULTIPLIER_ABOVE_10 = 1.4;

    /** Multiplicador do mínimo quando custo > 4 (+50%). */
    public const MIN_MULTIPLIER_ABOVE_4 = 1.5;

    /** Multiplicador do mínimo padrão — custo ≤ 4 (+60%). */
    public const MIN_MULTIPLIER_DEFAULT = 1.6;

    // --- Tiers do máximo ---

    /** Custo abaixo deste valor aplica multiplicador MAX_MULTIPLIER_LOW_COST. */
    public const MAX_COST_THRESHOLD_LOW = 1;

    /** Multiplicador do máximo para keys de baixo custo (< 1). */
    public const MAX_MULTIPLIER_LOW_COST = 30;

    /** Multiplicador do máximo padrão — custo ≥ 1 (e para override de mercado). */
    public const MAX_MULTIPLIER_DEFAULT = 8;

    /**
     * Aplica os limites min_api / max_api ao preço calculado.
     * Quando $limits é null (produto sem key no banco), aplica apenas FLOOR e CEILING.
     *
     * @param  array{min_api: float, max_api: float}|null  $limits
     */
    public static function clamp(float $price, ?array $limits): float
    {
        $min = $limits !== null ? max($limits['min_api'], self::FLOOR) : self::FLOOR;
        $max = $limits !== null ? min($limits['max_api'], self::CEILING) : self::CEILING;

        return max($min, min($price, $max));
    }

    /**
     * @return array{min: float, max: float}
     */
    public static function calculate(float $individualCost, float $clientPrice): array
    {
        $min = self::computeMin($individualCost);
        $max = self::computeMax($individualCost, $clientPrice);

        return [
            'min' => max($min, self::FLOOR),
            'max' => max($max, self::FLOOR),
        ];
    }

    private static function computeMin(float $individualCost): float
    {
        if ($individualCost > self::MIN_COST_THRESHOLD_HIGH) {
            return $individualCost * self::MIN_MULTIPLIER_ABOVE_10;
        }

        if ($individualCost > self::MIN_COST_THRESHOLD_MID) {
            return $individualCost * self::MIN_MULTIPLIER_ABOVE_4;
        }

        return $individualCost * self::MIN_MULTIPLIER_DEFAULT;
    }

    private static function computeMax(float $individualCost, float $clientPrice): float
    {
        $max = $individualCost < self::MAX_COST_THRESHOLD_LOW
            ? $individualCost * self::MAX_MULTIPLIER_LOW_COST
            : $individualCost * self::MAX_MULTIPLIER_DEFAULT;

        // Quando o preço de mercado já ultrapassou o teto calculado,
        // redefine o máximo com base no preço de mercado atual.
        if ($clientPrice >= $max) {
            $max = $clientPrice * self::MAX_MULTIPLIER_DEFAULT;
        }

        return $max;
    }
}
