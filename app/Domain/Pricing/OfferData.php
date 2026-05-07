<?php

namespace App\Domain\Pricing;

/**
 * Value Object que representa uma oferta da Gamivo retornada por GET /products/{id}/offers.
 *
 * Imutável por design: campos readonly.
 */
final class OfferData
{
    /**
     * @param  int  $id  offerId (usado no PUT /offers/{id})
     * @param  string  $sellerName  Nome do vendedor na Gamivo
     * @param  float  $retailPrice  Preço com taxa — o que o cliente vê (€)
     * @param  int  $completedOrders  Total de pedidos concluídos (reputação)
     * @param  int  $wholesaleMode  0 = só varejo; 1 ou 2 = wholesale ativo
     */
    public function __construct(
        public readonly int $id,
        public readonly string $sellerName,
        public readonly float $retailPrice,
        public readonly int $completedOrders,
        public readonly int $wholesaleMode,
    ) {}

    /**
     * Constrói o VO a partir do array retornado pela API Gamivo.
     *
     * @param  array{id: int, seller_name: string, retail_price: float, completed_orders: int, wholesale_mode: int, ...}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            sellerName: (string) $data['seller_name'],
            retailPrice: (float) $data['retail_price'],
            completedOrders: (int) $data['completed_orders'],
            wholesaleMode: (int) $data['wholesale_mode'],
        );
    }
}
