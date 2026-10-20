<?php

namespace App\Domain\Games;

use App\Domain\Enums\GiveawayStatus;
use Carbon\Carbon;

/**
 * Por quanto tempo um jogo continua "em giveaway" — e por isso com valor de
 * mercado baixo demais para pagarmos o preço normal.
 *
 * O preço despenca quando o jogo sai de graça e não volta quando o giveaway
 * acaba: leva meses para subir, e quanto mais tempo o giveaway durou (mais
 * cópias distribuídas), mais demora. Por isso a janela depois do fim cresce
 * com a duração, em três faixas — valores e exemplos em docs/PRODUCT.md
 * (seção "Giveaway").
 *
 * Giveaway ainda ativo é considerado em giveaway sem prazo — e é avaliado
 * antes das faixas, porque ainda não tem duração fechada.
 */
final class GiveawayPolicy
{
    /** Duração máxima (dias, inclusiva) da faixa curta. */
    public const SHORT_MAX_DAYS = 15;

    /** Dias em giveaway depois do fim, para a faixa curta. */
    public const SHORT_WINDOW_DAYS = 90;

    /** Duração máxima (dias, inclusiva) da faixa média. */
    public const MEDIUM_MAX_DAYS = 30;

    /** Dias em giveaway depois do fim, para a faixa média. */
    public const MEDIUM_WINDOW_DAYS = 120;

    /** Dias em giveaway depois do fim, para giveaway mais longo que a faixa média. */
    public const LONG_WINDOW_DAYS = 180;

    /**
     * Quantos dias o giveaway durou, em dias de calendário.
     *
     * Conta pela data, não pelas horas: publicado às 23h e visto pela última
     * vez às 9h de 15 dias depois dura 15 dias, não 14.
     */
    public static function durationDays(GiveawayPeriod $period): int
    {
        return (int) $period->publishedDate->copy()->startOfDay()
            ->diffInDays($period->lastSeenAt->copy()->startOfDay(), true);
    }

    /** Por quantos dias depois do fim o jogo continua em giveaway. */
    public static function windowDays(int $durationDays): int
    {
        return match (true) {
            $durationDays <= self::SHORT_MAX_DAYS => self::SHORT_WINDOW_DAYS,
            $durationDays <= self::MEDIUM_MAX_DAYS => self::MEDIUM_WINDOW_DAYS,
            default => self::LONG_WINDOW_DAYS,
        };
    }

    /**
     * Até quando o jogo é considerado em giveaway.
     *
     * `null` para giveaway ativo: não há prazo enquanto ele estiver no feed.
     */
    public static function flaggedUntil(GiveawayPeriod $period): ?Carbon
    {
        if ($period->status === GiveawayStatus::Active) {
            return null;
        }

        return $period->lastSeenAt->copy()->addDays(self::windowDays(self::durationDays($period)));
    }

    /** O jogo deste giveaway ainda é considerado em giveaway em `$now`? O último instante da janela ainda conta. */
    public static function isFlagged(GiveawayPeriod $period, Carbon $now): bool
    {
        $until = self::flaggedUntil($period);

        return $until === null || $now->lessThanOrEqualTo($until);
    }

    /**
     * O jogo está em giveaway em `$now`, dados todos os giveaways dele?
     *
     * Basta um na janela: com mais de um giveaway do mesmo jogo, vale a janela
     * que termina por último, e um giveaway ainda na janela já a cobre. A
     * região não entra — o giveaway derruba o preço do jogo em todas.
     *
     * @param  iterable<GiveawayPeriod>  $periods
     */
    public static function isGameFlagged(iterable $periods, Carbon $now): bool
    {
        foreach ($periods as $period) {
            if (self::isFlagged($period, $now)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Desde quando um giveaway encerrado ainda pode estar na janela.
     *
     * É a maior janela possível, para a consulta pré-filtrar no banco sem
     * deixar nenhum de fora; quem decide caso a caso é [[self::isFlagged]].
     */
    public static function oldestFlaggableLastSeen(Carbon $now): Carbon
    {
        return $now->copy()->subDays(self::LONG_WINDOW_DAYS);
    }
}
