<?php

namespace App\Domain\Assets;

/**
 * Quão velha uma cotação pode ser e ainda valer.
 */
final class ExchangeRatePolicy
{
    /**
     * Idade máxima de uma cotação usada quando a API de câmbio está fora do ar.
     *
     * Dentro desse limite o preço convertido (bundle, ativo, alerta) usa a última
     * cotação boa; passado dele, a conversão falha — um número velho demais
     * distorceria preço e custo sem ninguém perceber.
     */
    public const MAX_STALE_AGE_SECONDS = 21600;
}
