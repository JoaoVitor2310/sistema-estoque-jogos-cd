<?php

namespace App\UseCases\Marketplaces\Gamivo\DTO;

use App\Domain\Enums\OrderPayoutAttribution;

/**
 * Resultado da leitura de um pedido do histórico da Gamivo: as baixas a aplicar e
 * o quanto do casamento linha↔key fechou.
 *
 * A atribuição anda junto das entradas porque um pedido pode gerar baixas corretas
 * e ainda assim ter caído no rateio igual — sem esse dado o resumo do scheduler não
 * distingue um pedido bem atribuído de um chute.
 */
final class OrderPayoutBreakdownDTO
{
    /**
     * @param  array<int, array{keys: string[], profit: float, saleDate: string}>  $entries
     */
    public function __construct(
        public readonly array $entries,
        public readonly OrderPayoutAttribution $attribution,
    ) {}
}
