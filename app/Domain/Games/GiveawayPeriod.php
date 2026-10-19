<?php

namespace App\Domain\Games;

use App\Domain\Enums\GiveawayStatus;
use Carbon\Carbon;

/**
 * O intervalo em que um giveaway esteve no ar, do nosso ponto de vista: de
 * quando foi publicado até a última vez que o vimos ativo no feed.
 *
 * É o que [[GiveawayPolicy]] precisa para decidir até quando o jogo continua
 * em giveaway. Num giveaway encerrado, `lastSeenAt` é o fim real — o
 * `end_date` anunciado pela API não entra aqui.
 */
final readonly class GiveawayPeriod
{
    public function __construct(
        public GiveawayStatus $status,
        public Carbon $publishedDate,
        public Carbon $lastSeenAt,
    ) {}

    /**
     * O início do giveaway: a data publicada pela API ou, sem ela, a primeira
     * vez que o vimos.
     *
     * O que já foi gravado vence o `$seenAt` de agora, para um giveaway que
     * perdeu a data no meio do caminho não reiniciar a contagem da duração.
     */
    public static function startDate(?Carbon $published, ?Carbon $recorded, Carbon $seenAt): Carbon
    {
        return $published ?? $recorded ?? $seenAt;
    }
}
