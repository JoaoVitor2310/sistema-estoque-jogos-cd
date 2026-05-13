<?php

namespace App\Domain\Pricing;

/**
 * Resultado do algoritmo de comparação de preços (ComparisonAlgorithm).
 *
 * Imutável por design: campos readonly. Use os named constructors.
 */
final class ComparisonResult
{
    private function __construct(
        /** Indica se o preço deve ser atualizado na Gamivo. */
        public readonly bool $shouldUpdate,

        /**
         * Motivo quando shouldUpdate = false.
         * Valores possíveis: 'no_competitors', 'already_best'
         */
        public readonly string $reason,

        /** Novo seller_price sem taxa a enviar para PUT /offers/{offerId}. */
        public readonly float $sellerPrice,

        /** ID da nossa oferta na Gamivo. */
        public readonly int $offerId,

        /** wholesale_mode da oferta (0 = só varejo, 1 ou 2 = wholesale ativo). */
        public readonly int $wholesaleMode,

        /** tier_one_seller_price (só relevante quando wholesaleMode != 0). */
        public readonly float $tierOneSellerPrice,

        /** tier_two_seller_price (só relevante quando wholesaleMode != 0). */
        public readonly float $tierTwoSellerPrice,

        /** Preço de varejo alvo calculado pelo algoritmo (antes da conversão para income). */
        public readonly float $targetRetail,
    ) {}

    /**
     * Nenhuma ação necessária — preço atual já é ótimo ou não há concorrentes.
     *
     * @param  string  $reason  'no_competitors' | 'already_best'
     */
    public static function noAction(string $reason): self
    {
        return new self(
            shouldUpdate: false,
            reason: $reason,
            sellerPrice: 0.0,
            offerId: 0,
            wholesaleMode: 0,
            tierOneSellerPrice: 0.0,
            tierTwoSellerPrice: 0.0,
            targetRetail: 0.0,
        );
    }

    /**
     * Novo preço calculado — deve atualizar via PUT /offers/{offerId}.
     *
     * @param  float  $sellerPrice  Novo seller_price sem taxa (€)
     * @param  int  $offerId  ID da nossa oferta
     * @param  int  $wholesaleMode  Modo wholesale da oferta
     * @param  float  $tierOneSellerPrice  Preço tier 1 wholesale (0.0 se wholesaleMode = 0)
     * @param  float  $tierTwoSellerPrice  Preço tier 2 wholesale (0.0 se wholesaleMode = 0)
     */
    public static function updatePrice(
        float $sellerPrice,
        int $offerId,
        int $wholesaleMode,
        float $tierOneSellerPrice = 0.0,
        float $tierTwoSellerPrice = 0.0,
        float $targetRetail = 0.0,
    ): self {
        return new self(
            shouldUpdate: true,
            reason: 'update_price',
            sellerPrice: $sellerPrice,
            offerId: $offerId,
            wholesaleMode: $wholesaleMode,
            tierOneSellerPrice: $tierOneSellerPrice,
            tierTwoSellerPrice: $tierTwoSellerPrice,
            targetRetail: $targetRetail,
        );
    }
}
