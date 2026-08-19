<?php

namespace App\Domain\Trades;

use App\Domain\Enums\TradeLineAuthority;

/**
 * Converte uma entrada do JSON legado `trades.games` nos atributos de uma
 * linha de trade.
 *
 * Existe separada da migration porque é o passo irreversível do backfill: a
 * conversão precisa ser exercitável em Unit, com as formas que realmente
 * existem em produção, sem depender de rodar a migration para ser conferida.
 *
 * Vive enquanto a migration viver: o arquivo de migration a referencia para
 * sempre, e uma base recriada do zero replaya a passada (sobre `trades` vazia,
 * onde ela não faz nada).
 *
 * **Não julga o dado**: toda entrada vira linha, inclusive incompleta ou
 * inteiramente vazia. O que não couber numa coluna tipada é normalizado por
 * [[TradeLineValue]], igual à escrita campo a campo de uma linha.
 */
final class LegacyTradeLine
{
    /**
     * @param  array<string, mixed>  $entry  uma entrada do array `trades.games`
     * @return array<string, mixed> atributos de `trade_lines`, sem o vínculo com a trade
     */
    public static function toAttributes(array $entry, int $position): array
    {
        return [
            'position' => $position,
            'game_name' => TradeLineValue::text($entry['name'] ?? null),
            'market_price' => TradeLineValue::decimal($entry['marketPriceRaw'] ?? null),
            'popularity' => TradeLineValue::integer($entry['popularity'] ?? null),
            'region' => TradeLineValue::text($entry['regionLock'] ?? null),
            'bundle' => TradeLineValue::text($entry['bundle'] ?? null),
            'expires_at' => TradeLineValue::date($entry['expiry'] ?? null, TradeLineAuthority::Team->dateFormat()),
            'key_code' => TradeLineValue::text($entry['keyCode'] ?? null),
            'gamivo_id' => TradeLineValue::text($entry['gamivoId'] ?? null),
        ];
    }
}
