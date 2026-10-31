<?php

namespace App\Domain\Pricing;

use App\Domain\Enums\PurchaseChannel;
use App\Domain\Pricing\ValueObjects\SupplierOfferLine;

/**
 * A oferta a um supplier por jogo: o lucro pedido, nunca acima do que o
 * min_api permite pagar.
 *
 * Peça única para todo caminho que oferta a supplier — a prospecção e a
 * oferta gravada na trade —, para as duas nunca chegarem a valores diferentes
 * pelo mesmo jogo.
 *
 * A negociação tem um número fixo de rodadas, e a última é a **final**, seja
 * qual for o valor dela. Não é final por ter chegado à oferta máxima: se fosse,
 * o supplier que insiste sempre arrancaria o menor lucro possível e ainda
 * aprenderia onde ele fica.
 */
final class SupplierOffer
{
    /** A primeira oferta de uma trade. */
    public const FIRST_ROUND = 1;

    /** Oferta inicial e duas contrapropostas: mais que isso fica repetitivo para o supplier. */
    public const MAX_ROUNDS = 3;

    /**
     * Quantos pontos de lucro o Estoque cede a cada contraproposta. Com 10, um
     * supplier Normal vai de 70% a 60% e depois a 50%. Degrau fixo e pequeno:
     * pedir muito não leva direto à oferta máxima.
     */
    public const ROUND_PROFIT_STEP = 10;

    /**
     * Oferta de um jogo que rende `$netIncome` na Gamivo.
     *
     * `null` quando não há oferta positiva: o jogo não paga as taxas, ou ainda
     * não há preço da TF2 em euro para converter.
     *
     * @param  float  $netIncome  Income líquido após taxas Gamivo (€)
     * @param  float  $profitPercent  Lucro pedido (ex: 70.0 para 70%)
     * @param  float  $tf2EuroPrice  Preço de 1 TF2 key em euros
     */
    public static function line(float $netIncome, float $profitPercent, float $tf2EuroPrice, PurchaseChannel $channel): ?SupplierOfferLine
    {
        if ($netIncome <= 0 || $tf2EuroPrice <= 0) {
            return null;
        }

        $target = OfferCalculator::tf2Offer($netIncome, $profitPercent, $tf2EuroPrice);
        $max = MinimumMarginPolicy::maxInitialCost($netIncome, $channel) / $tf2EuroPrice;

        return new SupplierOfferLine(min($target, $max), $max);
    }

    /**
     * O lucro-alvo de uma rodada: o da primeira oferta, menos um degrau por
     * contraproposta. Pode ficar abaixo da margem do min_api — aí quem segura
     * o valor de cada jogo é a oferta máxima.
     *
     * @throws \InvalidArgumentException para rodada fora de 1..MAX_ROUNDS
     */
    public static function roundProfitPercent(float $firstRoundProfitPercent, int $round): float
    {
        self::isFinalRound($round);

        return $firstRoundProfitPercent - self::ROUND_PROFIT_STEP * ($round - self::FIRST_ROUND);
    }

    /**
     * A rodada é a final: depois dela não há contraproposta.
     *
     * @throws \InvalidArgumentException para rodada fora de 1..MAX_ROUNDS
     */
    public static function isFinalRound(int $round): bool
    {
        if ($round < self::FIRST_ROUND || $round > self::MAX_ROUNDS) {
            throw new \InvalidArgumentException('Offer round must be between 1 and '.self::MAX_ROUNDS.", got {$round}");
        }

        return $round === self::MAX_ROUNDS;
    }
}
