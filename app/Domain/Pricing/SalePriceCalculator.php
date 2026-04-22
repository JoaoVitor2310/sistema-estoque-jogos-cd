<?php

namespace App\Domain\Pricing;

/**
 * Regras de precificação de venda de keys.
 *
 * Responsável por:
 *  - Calcular o preço mínimo aceitável para listar uma key (minimoParaVenda)
 *  - Gerar o rótulo padronizado que descreve o custo de um lote de compra (valorPagoTotal)
 */
final class SalePriceCalculator
{
    /**
     * Markup mínimo sobre o preço de mercado para cobrir variações de câmbio
     * e garantir margem de segurança na listagem.
     */
    private const MINIMUM_SALE_MARKUP = 1.05;

    /**
     * Calcula o preço mínimo aceitável para listar a key.
     *
     * O mínimo é definido como 5% acima do preço de mercado na data de compra,
     * garantindo que a key não seja vendida abaixo do valor original pago pelo cliente.
     *
     * @param  float  $clientPrice  Preço de mercado na data de compra (precoCliente)
     */
    public static function minimumSalePrice(float $clientPrice): float
    {
        return $clientPrice * self::MINIMUM_SALE_MARKUP;
    }

    /**
     * Gera o rótulo padronizado que descreve o custo da trade.
     *
     * Formato: "{qtdTF2}x TF2 Keys / {totalGames}"
     * Exemplo: "2x TF2 Keys / 5" — indica que 2 TF2 keys pagaram por 5 jogos do lote.
     *
     * @param  float  $qtdTF2  Quantidade de TF2 keys usadas na troca
     * @param  int  $totalGames  Total de jogos no lote de compra
     */
    public static function tradeCostLabel(float $qtdTF2, int $totalGames): string
    {
        return $qtdTF2.'x TF2 Keys / '.$totalGames;
    }
}
