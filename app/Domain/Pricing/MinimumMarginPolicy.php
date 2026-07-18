<?php

namespace App\Domain\Pricing;

use App\Domain\Keys\KeyEligibility;
use Carbon\Carbon;

/**
 * Fonte única do piso de preço (min_api) de uma key.
 *
 * Dois grupos de regra, resolvidos em ordem:
 *
 * FLOORs absolutos (minApi()) — incondicionais, valem independente de status
 * de listagem, e não competem entre si porque resultam no mesmo valor:
 *  - Expira em <= EXPIRY_PRICE_FLOOR_DAYS dias    → FLOOR
 *  - Comprada há >= OLD_KEY_MONTHS meses          → FLOOR (estoque muito parado, liquidar)
 *  - Listada há >= LIMBO_MONTHS_THRESHOLD meses   → FLOOR (muito tempo à venda sem comprador, liquidar)
 *
 * Uma vez que "comprada há >= OLD_KEY_MONTHS" passa a valer, ela nunca deixa
 * de valer, listada ou não (acquired_at não muda e "agora" só anda para
 * frente) — a key não "recupera" uma margem normal só por ter sido listada.
 * Isso também evita min_api > max_api quando o AutoSellUseCase trava o
 * max_api dessas keys no preço de listagem.
 *
 * Margem percentual (requiredMargin()) — só chega aqui se nenhum FLOOR acima
 * bateu. Aqui sim a decisão se divide por cenário, porque cada um usa uma
 * base de tempo diferente:
 *
 * Não listada (decaimento por tempo de estoque, via acquired_at):
 *  - Comprada há >= UNLISTED_AGING_MONTHS meses     → 15%
 *  - Comprada há >= UNLISTED_MODERATE_MONTHS meses  → 40%
 *  - Default — apenas custo                          → custo × multiplicador
 *
 * Listada (decaimento por tempo listado, via listed_at):
 *  - Listada há >= LISTED_AGING_MONTHS meses    → 20%
 *  - Listada há >= LISTED_MODERATE_MONTHS meses → 30%
 *  - Listada há >= LISTED_EARLY_MONTHS meses    → 40%
 *  - Default — apenas custo                      → custo × multiplicador
 */
final class MinimumMarginPolicy
{
    /** Margem padrão quando nenhum outro tier se aplica (€1 ≤ custo ≤ €10, key jovem). */
    public const DEFAULT_MARGIN = 0.60;

    /** Margem exigida para keys de custo muito baixo (< €1) — margem relativa maior compensa o valor absoluto baixo. */
    public const LOW_COST_MARGIN = 0.55;

    /** Margem exigida para keys de custo alto (> €10 e ≤ €15). */
    public const HIGH_COST_MARGIN = 0.45;

    /** Margem exigida para keys de custo muito alto (> €15) — mercado de itens caros costuma cair mais. */
    public const VERY_HIGH_COST_MARGIN = 0.40;

    /** Limiar de custo abaixo do qual se aplica LOW_COST_MARGIN. */
    public const LOW_COST_THRESHOLD = 1.0;

    /** Limiar de custo acima do qual se aplica HIGH_COST_MARGIN. */
    public const HIGH_COST_THRESHOLD = 10.0;

    /** Limiar de custo acima do qual se aplica VERY_HIGH_COST_MARGIN. */
    public const VERY_HIGH_COST_THRESHOLD = 15.0;

    /** Meses listada a partir dos quais se aplica LISTED_AGING_MARGIN. */
    public const LISTED_AGING_MONTHS = 6;

    /** Meses listada a partir dos quais se aplica LISTED_MODERATE_MARGIN. */
    public const LISTED_MODERATE_MONTHS = 4;

    /** Meses listada a partir dos quais se aplica LISTED_EARLY_MARGIN. */
    public const LISTED_EARLY_MONTHS = 3;

    /** Margem para keys listadas há >= LISTED_AGING_MONTHS meses sem vender. */
    public const LISTED_AGING_MARGIN = 0.20;

    /** Margem para keys listadas há >= LISTED_MODERATE_MONTHS meses sem vender. */
    public const LISTED_MODERATE_MARGIN = 0.30;

    /** Margem para keys listadas há >= LISTED_EARLY_MONTHS meses sem vender. */
    public const LISTED_EARLY_MARGIN = 0.40;

    /** Meses listada a partir dos quais a key é liquidada ao FLOOR, independente de custo ou mercado. */
    public const LIMBO_MONTHS_THRESHOLD = 10;

    /** Meses de estoque (não listada) a partir dos quais se aplica UNLISTED_AGING_MARGIN. */
    public const UNLISTED_AGING_MONTHS = 6;

    /** Meses de estoque (não listada) a partir dos quais se aplica UNLISTED_MODERATE_MARGIN. */
    public const UNLISTED_MODERATE_MONTHS = 4;

    /** Margem para keys não listadas com >= UNLISTED_AGING_MONTHS meses de estoque. */
    public const UNLISTED_AGING_MARGIN = 0.15;

    /** Margem para keys não listadas com >= UNLISTED_MODERATE_MONTHS meses de estoque. */
    public const UNLISTED_MODERATE_MARGIN = 0.40;

    /**
     * Margem mínima (percentual, ex: 0.60 = 60%) exigida para uma key com o
     * custo, idade e status de listagem dados.
     *
     * Quando $listedAt é informado, o decaimento por tempo listado substitui
     * o decaimento por tempo de estoque — uma vez listada, o que importa é há
     * quanto tempo está visível no mercado sem vender, não há quanto tempo
     * foi comprada. Não cobre o FLOOR de limbo (>= LIMBO_MONTHS_THRESHOLD)
     * nem o de estoque antigo (>= OLD_KEY_MONTHS) — esses são resolvidos em
     * minApi(), pois não fazem sentido como percentual.
     */
    public static function requiredMargin(
        float $individualCost,
        Carbon $acquiredAt,
        ?Carbon $listedAt = null,
        ?Carbon $now = null,
    ): float {
        $now ??= Carbon::now();
        $cost = max($individualCost, MinMaxPriceCalculator::FLOOR);

        if ($listedAt !== null) {
            return match (true) {
                $listedAt->lt($now->copy()->subMonths(self::LISTED_AGING_MONTHS)) => self::LISTED_AGING_MARGIN,
                $listedAt->lt($now->copy()->subMonths(self::LISTED_MODERATE_MONTHS)) => self::LISTED_MODERATE_MARGIN,
                $listedAt->lt($now->copy()->subMonths(self::LISTED_EARLY_MONTHS)) => self::LISTED_EARLY_MARGIN,
                default => self::costTierMargin($cost),
            };
        }

        return match (true) {
            $acquiredAt->lt($now->copy()->subMonths(self::UNLISTED_AGING_MONTHS)) => self::UNLISTED_AGING_MARGIN,
            $acquiredAt->lt($now->copy()->subMonths(self::UNLISTED_MODERATE_MONTHS)) => self::UNLISTED_MODERATE_MARGIN,
            default => self::costTierMargin($cost),
        };
    }

    /**
     * Preço mínimo (min_api) final para a key.
     *
     * Dois cenários mutuamente exclusivos, cada um com seu próprio FLOOR:
     *  - Não listada: FLOOR se comprada há >= OLD_KEY_MONTHS meses.
     *  - Listada: FLOOR se listada há >= LIMBO_MONTHS_THRESHOLD meses.
     *
     * Não aplica o piso absoluto de API (MinMaxPriceCalculator::FLOOR) além
     * desses overrides — quem chama decide o clamp final.
     */
    public static function minApi(
        float $individualCost,
        Carbon $acquiredAt,
        ?Carbon $listedAt = null,
        ?Carbon $expiresAt = null,
        ?Carbon $now = null,
    ): float {
        $now ??= Carbon::now();

        if (self::isExpiringSoon($expiresAt, $now)) {
            return MinMaxPriceCalculator::FLOOR;
        }

        // Os dois FLOORs abaixo valem incondicionalmente, independente do status
        // de listagem — não competem entre si porque resultam no mesmo valor.
        // Uma key comprada há muito tempo não deve "recuperar" uma margem normal
        // só por ter sido listada (isso quebraria o max_api travado pelo
        // AutoSellUseCase para keys velhas, gerando min_api > max_api).
        if ($acquiredAt->lt($now->copy()->subMonths(KeyEligibility::OLD_KEY_MONTHS))) {
            return MinMaxPriceCalculator::FLOOR;
        }

        if ($listedAt !== null && $listedAt->lt($now->copy()->subMonths(self::LIMBO_MONTHS_THRESHOLD))) {
            return MinMaxPriceCalculator::FLOOR;
        }

        $cost = max($individualCost, MinMaxPriceCalculator::FLOOR);
        $margin = self::requiredMargin($cost, $acquiredAt, $listedAt, $now);

        return round($cost * (1 + $margin), 2);
    }

    private static function costTierMargin(float $cost): float
    {
        return match (true) {
            $cost > self::VERY_HIGH_COST_THRESHOLD => self::VERY_HIGH_COST_MARGIN,
            $cost > self::HIGH_COST_THRESHOLD => self::HIGH_COST_MARGIN,
            $cost < self::LOW_COST_THRESHOLD => self::LOW_COST_MARGIN,
            default => self::DEFAULT_MARGIN,
        };
    }

    private static function isExpiringSoon(?Carbon $expiresAt, Carbon $now): bool
    {
        if ($expiresAt === null) {
            return false;
        }

        return $expiresAt->between($now, $now->copy()->addDays(KeyEligibility::EXPIRY_PRICE_FLOOR_DAYS));
    }
}
