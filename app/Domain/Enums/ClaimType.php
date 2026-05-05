<?php

namespace App\Domain\Enums;

enum ClaimType: string
{
    case Nenhuma = 'Nenhuma';
    case Dup = 'Dup';
    case Rev = 'Rev';
    case Reg = 'Reg';
}
