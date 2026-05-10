<?php

namespace App\Domain\Keys;

/**
 * Estado inicial canônico de uma key recém-cadastrada.
 *
 * Centraliza os defaults de domínio para que qualquer ponto de entrada
 * (importação XLSX, TradeCalculator, API futura) receba exatamente os
 * mesmos valores sem duplicação.
 */
final class KeyDefaults
{
    /**
     * Tipo de reclamação padrão: nenhum problema registrado.
     */
    public const CLAIM_TYPE = 'Nenhuma';

    /**
     * Formato de key padrão: Retail Key.
     */
    public const KEY_FORMAT = 'RK';

    /**
     * Plataforma de venda padrão.
     */
    public const SELL_PLATFORM = 'Gamivo';

    /**
     * Retorna o mapa de defaults para campos não fornecidos pelo caller.
     *
     * Usado via array_merge($defaults, $game) — campos explícitos no $game
     * prevalecem; campos ausentes recebem o valor padrão.
     *
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        return [
            'gamivo_id' => null,
            'steam_id' => null,
            'minimum_sale_price' => null,
            'min_api' => null,
            'max_api' => null,
            'claim_type' => self::CLAIM_TYPE,
            'key_format' => self::KEY_FORMAT,
            'sell_platform' => self::SELL_PLATFORM,
            'listed_at' => null,
            'sold_at' => null,
            'sold_price' => null,
            'notes' => null,
            'total_paid' => null,
            'color' => null,
        ];
    }
}
