<?php

namespace App\Domain\Keys;

use Carbon\Carbon;

/**
 * Regras de elegibilidade de uma key para listagem automática de venda.
 *
 * A query de banco (com JOINs em bundle_games/bundles) é responsabilidade
 * do Repository; o Domain recebe os dados já hidratados e decide.
 *
 * Regras para autoSell():
 *  1. Deve ter idGamivo preenchido (cadastrado no marketplace)
 *  2. Não pode estar já listada (dataVenda nula)
 *  3. Não pode estar já vendida (dataVendida nula)
 *  4. Não pode ser gift link (URL no código da key)
 *  5. O jogo não pode estar em bundle lançado nos últimos 21 dias
 */
final class KeyEligibility
{
    /** Janela de exclusão após lançamento de bundle (dias) — usada em AutoSellUseCase. */
    public const BUNDLE_EXCLUSION_DAYS = 21;

    /** Antecedência (dias) com que o alerta de expiração de key é disparado. */
    public const EXPIRY_ALERT_DAYS = 30;

    /** Janela (dias) dentro da qual o min_api de keys listadas é reduzido ao piso. */
    public const EXPIRY_PRICE_FLOOR_DAYS = 30;

    /**
     * Idade (meses) a partir da qual uma key é considerada "velha":
     * o AutoSellUseCase ignora o piso min_api e lista independentemente do preço de mercado.
     * Após a listagem, min_api é atualizado para o preço praticado, permitindo repricing futuro.
     */
    public const OLD_KEY_MONTHS = 10;

    /**
     * Idade (meses) a partir da qual o ReduceAgingKeysMinPriceUseCase
     * reduz o min_api para AGING_KEY_MIN_API_MULTIPLIER × individual_cost,
     * facilitando a listagem pelo AutoSell em mercados que caíram desde a aquisição.
     */
    public const AGING_KEY_MONTHS = 7;

    /**
     * Multiplicador aplicado ao individual_cost para calcular o novo min_api
     * de keys com >= AGING_KEY_MONTHS meses no estoque.
     * Ex: custo = €2,00 → novo min_api = €2,40 (margem mínima de 20%).
     *
     * A margem mínima de lucro equivalente é AGING_KEY_MIN_API_MULTIPLIER - 1 (= 0.20),
     * usada em hasMinimumProfitForAutoSell para manter coerência entre os dois mecanismos.
     */
    public const AGING_KEY_MIN_API_MULTIPLIER = 1.2;

    /**
     * Idade (meses) a partir da qual a exigência de margem mínima começa a cair
     * no hasMinimumProfitForAutoSell e o ReduceAgingKeysMinPriceUseCase reduz o
     * min_api para MODERATE_AGE_MIN_API_MULTIPLIER × individual_cost.
     */
    public const MODERATE_AGE_MONTHS = 4;

    /**
     * Multiplicador aplicado ao individual_cost para calcular o novo min_api
     * de keys com >= MODERATE_AGE_MONTHS e < AGING_KEY_MONTHS meses no estoque.
     * Ex: custo = €2,00 → novo min_api = €3,00 (margem mínima de 50%).
     *
     * A margem mínima de lucro equivalente é MODERATE_AGE_MIN_API_MULTIPLIER - 1 (= 0.50),
     * usada em hasMinimumProfitForAutoSell para manter coerência com o ReduceAgingKeysMinPriceUseCase.
     */
    public const MODERATE_AGE_MIN_API_MULTIPLIER = 1.5;

    /**
     * Avalia se uma key está elegível para listagem automática.
     *
     * @param  string|null  $gamivoId  ID da key no Gamivo (null = não cadastrada)
     * @param  string|null  $keyCode  Código da key (null = não recebida ainda)
     * @param  Carbon|null  $listedAt  Data em que foi listada para venda (null = não listada)
     * @param  Carbon|null  $soldAt  Data de venda (null = não vendida)
     * @param  Carbon|null  $newestBundleRelease  Release date do bundle mais recente do jogo (null = sem bundle)
     */
    public static function isEligibleForAutoSell(
        ?string $gamivoId,
        ?string $keyCode,
        ?Carbon $listedAt,
        ?Carbon $soldAt,
        ?Carbon $newestBundleRelease,
    ): bool {
        // Regra 1: deve ter ID no marketplace
        if (empty($gamivoId)) {
            return false;
        }

        // Regra 2 e 3: não pode estar listada nem vendida
        if ($listedAt !== null || $soldAt !== null) {
            return false;
        }

        // Regra 4: gift links são URLs, não códigos de ativação
        if ($keyCode !== null && str_contains($keyCode, 'http')) {
            return false;
        }

        // Regra 5: jogo em bundle recente não deve ser listado
        if ($newestBundleRelease !== null) {
            $cutoff = Carbon::now()->subDays(self::BUNDLE_EXCLUSION_DAYS);
            if ($newestBundleRelease->isAfter($cutoff)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verifica se o preço de listagem gera lucro suficiente para justificar o auto-sell.
     *
     * Idade tem prioridade sobre custo: uma key parada há meses deve ser vendida com
     * critérios mais permissivos, independentemente do seu custo original.
     * Keys com >= OLD_KEY_MONTHS meses não chegam aqui — o AutoSellUseCase bypassa
     * o método inteiro via age override.
     *
     * Regras first-match-wins:
     *  1. ≥ AGING_KEY_MONTHS meses  → AGING_KEY_MIN_API_MULTIPLIER - 1 (20%) — alinhado ao min_api já reduzido
     *  2. ≥ MODERATE_AGE_MONTHS meses → 50%
     *  3. custo > €15               → 50%  (mercado de itens caros costuma cair mais)
     *  4. custo > €10               → 60%
     *  5. custo < €1                → 75%  (margem relativa maior compensa valor absoluto baixo)
     *  6. default                   → 78%
     *
     * @param  float  $sellerPrice  Preço de listagem calculado pelo ComparisonAlgorithm
     * @param  float  $individualCost  Custo individual pago pela key
     * @param  Carbon  $acquiredAt  Data de aquisição da key
     */
    public static function hasMinimumProfitForAutoSell(
        float $sellerPrice,
        float $individualCost,
        Carbon $acquiredAt,
    ): bool {
        // Protege contra custo zero ou negativo (dado inválido no banco)
        $cost = max($individualCost, 0.01);
        $profit = $sellerPrice - $cost;

        $minPercent = match (true) {
            $acquiredAt->lt(Carbon::now()->subMonths(self::AGING_KEY_MONTHS)) => self::AGING_KEY_MIN_API_MULTIPLIER - 1,
            $acquiredAt->lt(Carbon::now()->subMonths(self::MODERATE_AGE_MONTHS)) => self::MODERATE_AGE_MIN_API_MULTIPLIER - 1,
            $cost > 15.0 => 0.50,
            $cost > 10.0 => 0.60,
            $cost < 1.0 => 0.75,
            default => 0.78,
        };

        return $profit >= $minPercent * $cost;
    }
}
