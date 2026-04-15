<?php

namespace App\Domain\Pricing;

/**
 * Calcula os preços mínimo e máximo que a API do Gamivo pode atingir
 * ao ajustar automaticamente o preço de uma key.
 *
 * PHP puro — zero dependência do Laravel ou do banco de dados.
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
    private const FLOOR = 0.02;

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

    private static function computeMin(float $valorPago): float
    {
        if ($valorPago > 10) {
            return $valorPago * 1.4;
        }

        if ($valorPago > 4) {
            return $valorPago * 1.5;
        }

        return $valorPago * 1.6;
    }

    private static function computeMax(float $valorPago, float $precoCliente): float
    {
        $max = $valorPago < 1
            ? $valorPago * 30
            : $valorPago * 8;

        // Quando o preço de mercado já ultrapassou o teto calculado,
        // redefine o máximo com base no preço de mercado atual.
        if ($precoCliente >= $max) {
            $max = $precoCliente * 8;
        }

        return $max;
    }
}
