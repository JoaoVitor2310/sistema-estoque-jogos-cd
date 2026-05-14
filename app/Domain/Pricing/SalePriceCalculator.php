<?php

namespace App\Domain\Pricing;

/**
 * Regras de precificação de venda de keys.
 *
 * Responsável por:
 *  - Gerar o rótulo padronizado que descreve o custo de um lote de compra (valorPagoTotal)
 */
final class SalePriceCalculator
{
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
