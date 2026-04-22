<?php

namespace App\Domain\Enums;

enum SellPlatform: string
{
    case Nenhuma = 'Nenhuma';
    case G2A     = 'G2A';
    case Gamivo  = 'Gamivo';
    case Kinguin = 'Kinguin';
}
