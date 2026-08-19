<?php

namespace Tests\Support;

use App\UseCases\Trades\DTO\TradeLineDTO;

/**
 * DTO de linha para os testes dos UseCases de trade line.
 *
 * Namespaced pelo mesmo motivo de [[TradeFactory]]: helper solto no topo de um
 * arquivo de teste é promovido ao namespace global pelo Pest e colide com o
 * mesmo nome em outro arquivo — e este é usado pelos três UseCases de linha.
 */
final class TradeLineFactory
{
    /** @param  array<string, mixed>  $payload */
    public static function dto(array $payload = []): TradeLineDTO
    {
        return TradeLineDTO::fromValidated($payload);
    }
}
