<?php

namespace App\Domain\Pricing;

use Carbon\Carbon;

/**
 * Calcula os preços mínimo e máximo que a API do Gamivo pode atingir
 * ao ajustar automaticamente o preço de uma key.
 *
 * O mínimo delega a MinimumMarginPolicy — fonte única da margem mínima
 * aceitável (custo + idade da key). Ver MinimumMarginPolicy para os tiers.
 *
 * Tiers do máximo:
 *   valorPago < 1        → valorPago × 30
 *   valorPago >= 1       → valorPago × 8
 *   precoCliente >= max  → precoCliente × 8 (override: mercado já ultrapassou o teto)
 *
 * Esses tiers são folga para valorização, não um preço-alvo: quem freia o preço
 * de verdade é o concorrente, contra quem o ComparisonAlgorithm mira. Quando não
 * há concorrente o freio some e a folga viraria o preço praticado — para esse
 * caso existe soleSellerPrice(), ancorado no mercado pesquisado.
 *
 * Piso de 0.02 aplicado a ambos os valores.
 */
final class MinMaxPriceCalculator
{
    /** Piso absoluto de seller_price (guard-rail inferior). */
    public const FLOOR = 0.02;

    /** Teto absoluto de seller_price (guard-rail superior). */
    public const CEILING = 500.0;

    // --- Tiers do máximo ---

    /** Custo abaixo deste valor aplica multiplicador MAX_MULTIPLIER_LOW_COST. */
    public const MAX_COST_THRESHOLD_LOW = 1;

    /** Multiplicador do máximo para keys de baixo custo (< 1). */
    public const MAX_MULTIPLIER_LOW_COST = 30;

    /** Multiplicador do máximo padrão — custo ≥ 1 (e para override de mercado). */
    public const MAX_MULTIPLIER_DEFAULT = 8;

    /**
     * Multiplicador aplicado ao market_price quando o produto não tem concorrente
     * utilizável. Vive em unidade de seller_price (payout), não de vitrine: com as
     * taxas vigentes da Gamivo o retail resultante fica em torno de 122%-126% do
     * mercado, variando com a faixa de preço porque a taxa fixa pesa mais embaixo.
     */
    public const NO_COMPETITOR_MARKET_MULTIPLIER = 1.10;

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
     * Preço a praticar num produto sem concorrente utilizável.
     *
     * O teto de valorização (max_api) só é seguro enquanto existe um concorrente
     * servindo de freio. Sozinhos no produto, esse teto vira o preço praticado e
     * passamos a pedir múltiplos do valor real do jogo. Aqui a âncora é o mercado
     * pesquisado na compra, um pouco acima dele: o bastante para lucrar, baixo o
     * bastante para seguir competitivo com as lojas vizinhas.
     *
     * O max_api continua valendo como limite superior, e não como âncora: o teto
     * efetivo é o MENOR entre o de mercado e ele. Na prática ele quase nunca binda
     * (a fórmula do import o deixa bem acima do mercado), mas quando alguém edita o
     * max_api na tela de Keys, ou quando o auto-sell o trava numa key velha, a
     * coluna precisa significar teto nos dois regimes — do contrário a tela promete
     * um limite que a precificação ignora.
     *
     * O piso vence: quando o resultado fica abaixo do min_api, sai o min_api —
     * a ausência de concorrência não é motivo para vender abaixo da margem exigida.
     * Sem concorrente ninguém nos corta, e o min_api decai sozinho com o tempo
     * (MinimumMarginPolicy), então o preço desce por conta própria.
     */
    public static function soleSellerPrice(float $marketPrice, float $minApi, float $maxApi): float
    {
        $marketCeiling = round($marketPrice * self::NO_COMPETITOR_MARKET_MULTIPLIER, 2);

        return self::clamp($marketCeiling, [
            'min_api' => $minApi,
            'max_api' => min($marketCeiling, $maxApi),
        ]);
    }

    /**
     * @return array{min: float, max: float}
     */
    public static function calculate(float $individualCost, float $clientPrice, Carbon $acquiredAt): array
    {
        $min = MinimumMarginPolicy::minApi($individualCost, $acquiredAt);
        $max = self::computeMax($individualCost, $clientPrice);

        return [
            'min' => max($min, self::FLOOR),
            'max' => max($max, self::FLOOR),
        ];
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
