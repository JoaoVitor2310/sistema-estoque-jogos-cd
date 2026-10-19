<?php

namespace App\Domain\Enums;

/**
 * Estado de um giveaway do GamerPower, do nosso ponto de vista.
 *
 *  - Active : apareceu na última consulta bem-sucedida ao feed
 *  - Ended  : sumiu do feed numa consulta bem-sucedida — o fim real, que o
 *             `end_date` anunciado pela API nem sempre informa ("N/A")
 */
enum GiveawayStatus: string
{
    case Active = 'active';
    case Ended = 'ended';
}
