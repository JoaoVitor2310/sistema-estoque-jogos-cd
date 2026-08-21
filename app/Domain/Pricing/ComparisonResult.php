<?php

namespace App\Domain\Pricing;

/**
 * Resultado do algoritmo de comparação de preços (ComparisonAlgorithm).
 *
 * Imutável por design: campos readonly. Use os named constructors.
 */
final class ComparisonResult
{
    /** Nenhuma ação: não há concorrente utilizável **e** não temos oferta no produto. */
    public const REASON_NO_COMPETITORS = 'no_competitors';

    /** Nenhuma ação: já ocupamos a melhor posição alcançável no ranking. */
    public const REASON_ALREADY_BEST = 'already_best';

    /** Nossa oferta é a única utilizável do produto — precificar pelo mercado pesquisado. */
    public const REASON_SOLE_SELLER = 'sole_seller';

    /** Preço novo calculado contra um concorrente. */
    public const REASON_UPDATE_PRICE = 'update_price';

    private function __construct(
        /** Indica se o preço deve ser atualizado na Gamivo. */
        public readonly bool $shouldUpdate,

        /**
         * Motivo da decisão. Ver as constantes REASON_*.
         * Quando shouldUpdate = false: 'no_competitors', 'already_best' ou 'sole_seller'.
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
     * @param  string  $reason  REASON_NO_COMPETITORS ou REASON_ALREADY_BEST
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
     * Somos o único vendedor utilizável do produto: nenhum concorrente serve de
     * âncora, mas nossa oferta existe e pode ser reprecificada.
     *
     * Diferente de noAction(REASON_NO_COMPETITORS), que cobre também o caso de não
     * termos oferta alguma — por isso ali o offerId é zerado e aqui é preservado:
     * é ele que permite ao chamador reprecificar a oferta pelo mercado pesquisado.
     *
     * sellerPrice fica 0.0 de propósito: o preço não sai daqui (o algoritmo só
     * conhece o mercado da Gamivo, não o market_price da key), e o AutoSellUseCase
     * usa esse zero como sinal de "produto sem mercado para ancorar".
     */
    public static function soleSeller(int $offerId, int $wholesaleMode): self
    {
        return new self(
            shouldUpdate: false,
            reason: self::REASON_SOLE_SELLER,
            sellerPrice: 0.0,
            offerId: $offerId,
            wholesaleMode: $wholesaleMode,
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
            reason: self::REASON_UPDATE_PRICE,
            sellerPrice: $sellerPrice,
            offerId: $offerId,
            wholesaleMode: $wholesaleMode,
            tierOneSellerPrice: $tierOneSellerPrice,
            tierTwoSellerPrice: $tierTwoSellerPrice,
            targetRetail: $targetRetail,
        );
    }
}
