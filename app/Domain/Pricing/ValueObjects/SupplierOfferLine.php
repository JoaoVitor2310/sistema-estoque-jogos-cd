<?php

namespace App\Domain\Pricing\ValueObjects;

use App\Domain\Enums\TradeCurrency;
use App\Domain\Pricing\OfferCalculator;

/**
 * Quanto se oferta a um supplier por um jogo, em TF2 Keys.
 *
 * Guarda a oferta **exata**, sem arredondar: é dela que sai o valor em
 * qualquer moeda, e converter um valor já arredondado compõe dois
 * arredondamentos e erra por centavos.
 */
final class SupplierOfferLine
{
    /**
     * @param  float  $tf2Offer  Oferta exata, já limitada à oferta máxima
     * @param  float  $maxTf2Offer  Oferta máxima: acima dela a key nasceria abaixo do min_api
     */
    public function __construct(
        public readonly float $tf2Offer,
        public readonly float $maxTf2Offer,
    ) {
        if ($tf2Offer <= 0) {
            throw new \InvalidArgumentException("tf2Offer must be positive, got {$tf2Offer}");
        }

        if ($tf2Offer > $maxTf2Offer) {
            throw new \InvalidArgumentException("tf2Offer {$tf2Offer} exceeds maxTf2Offer {$maxTf2Offer}");
        }
    }

    /**
     * A oferta chegou ao máximo que o min_api permite pagar pela linha. É
     * limite de segurança da linha, não fim de negociação: quem encerra é a
     * rodada ([[\App\Domain\Pricing\SupplierOffer::isFinalRound]]).
     */
    public function isAtMax(): bool
    {
        return $this->tf2Offer >= $this->maxTf2Offer;
    }

    /**
     * O valor que o supplier lê, na moeda da oferta, arredondado a 2 casas e
     * nunca abaixo do piso da moeda: ele não lê "0.00" ao lado de um jogo.
     */
    public function priceIn(TradeCurrency $currency, float $tf2PriceInCurrency): float
    {
        return $currency->isCash()
            ? OfferCalculator::inCurrency($this->tf2Offer, $tf2PriceInCurrency)
            : max(OfferCalculator::MIN_TF2_OFFER, round($this->tf2Offer, OfferCalculator::MONEY_DECIMALS));
    }

    /**
     * Se o piso da moeda cabe na oferta máxima da linha.
     *
     * O piso de [[self::priceIn]] sobe o valor de um jogo de centavos; se nem
     * o piso cabe na oferta máxima, pagá-lo já deixaria a key abaixo do
     * min_api, e o jogo fica fora da oferta.
     */
    public function fitsMinimumPriceIn(TradeCurrency $currency, float $tf2PriceInCurrency): bool
    {
        return $currency->isCash()
            ? $this->maxTf2Offer * $tf2PriceInCurrency >= OfferCalculator::MIN_CASH_OFFER
            : $this->maxTf2Offer >= OfferCalculator::MIN_TF2_OFFER;
    }
}
