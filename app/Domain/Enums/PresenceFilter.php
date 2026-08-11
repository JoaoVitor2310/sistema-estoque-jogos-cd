<?php

namespace App\Domain\Enums;

/**
 * Como um filtro de presença consulta uma coluna: pelas linhas que têm valor
 * ou pelas que não têm.
 *
 * Coluna vazia e coluna nula contam como a mesma coisa — é o critério que
 * Key::scopeWithGamivoId já usava para `gamivo_id`.
 */
enum PresenceFilter: string
{
    case Filled = 'filled';

    case Empty = 'empty';
}
