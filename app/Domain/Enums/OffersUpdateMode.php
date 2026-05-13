<?php

namespace App\Domain\Enums;

/**
 * Modo de execução do UpdateOffersUseCase.
 *
 * Usado para dividir a reprecificação em duas janelas de frequência:
 *  - WeAreLowest    : somos o mais barato → verificar a cada 5 minutos se podemos subir o preço
 *  - WeAreNotLowest : não somos o mais barato → verificar a cada hora para tentar recuperar posição
 */
enum OffersUpdateMode
{
    case WeAreLowest;
    case WeAreNotLowest;
}
