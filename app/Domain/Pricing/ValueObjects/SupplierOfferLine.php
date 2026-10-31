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
     * A oferta na moeda da oferta, **sem arredondar**: a TF2 vezes a cotação
     * em dinheiro, ou a própria TF2. Os dois usos abaixo partem daqui, cada um
     * com o arredondamento que o seu papel pede.
     */
    public function exactPriceIn(TradeCurrency $currency, float $tf2PriceInCurrency): float
    {
        return $currency->isCash() ? $this->tf2Offer * $tf2PriceInCurrency : $this->tf2Offer;
    }

    /**
     * O valor que o supplier lê: arredondado ao centavo **mais próximo** e
     * nunca abaixo do piso da moeda — ele não lê "0.00" ao lado de um jogo.
     */
    public function priceIn(TradeCurrency $currency, float $tf2PriceInCurrency): float
    {
        $minimum = $currency->isCash() ? OfferCalculator::MIN_CASH_OFFER : OfferCalculator::MIN_TF2_OFFER;

        return max($minimum, round($this->exactPriceIn($currency, $tf2PriceInCurrency), OfferCalculator::MONEY_DECIMALS));
    }

    /**
     * O limite desta oferta numa contraproposta, em centavos: arredondado
     * **para baixo**, porque limite que arredonda para cima pode passar meio
     * centavo da oferta máxima. É o único ponto em que o arredondamento difere
     * de [[self::priceIn]].
     */
    public function limitCentsIn(TradeCurrency $currency, float $tf2PriceInCurrency): int
    {
        // A folga absorve o erro de float de um valor exato como 2,61 virar 260,9999…
        return (int) floor($this->exactPriceIn($currency, $tf2PriceInCurrency) * 100 + 1e-9);
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
