<?php

namespace App\Domain\Keys;

use Carbon\Carbon;

/**
 * Regras de elegibilidade de uma key para listagem automática de venda.
 *
 * PHP puro — recebe apenas primitivos e Carbon.
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
    /** Janela de exclusão após lançamento de bundle (dias). */
    public const BUNDLE_EXCLUSION_DAYS = 21;

    /**
     * Avalia se uma key está elegível para listagem automática.
     *
     * @param string|null $gamivoId             ID da key no Gamivo (null = não cadastrada)
     * @param string|null $keyCode              Código da key (null = não recebida ainda)
     * @param Carbon|null $listedAt             Data em que foi listada para venda (null = não listada)
     * @param Carbon|null $soldAt               Data de venda (null = não vendida)
     * @param Carbon|null $newestBundleRelease  Release date do bundle mais recente do jogo (null = sem bundle)
     * @return bool
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
}
