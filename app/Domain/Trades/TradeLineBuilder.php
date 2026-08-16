<?php

namespace App\Domain\Trades;

use App\Domain\Games\GameNameNormalizer;

/**
 * Monta as linhas de uma trade a partir da saída do price-researcher.
 *
 * É o único lugar que conhece a forma de uma linha recém-pesquisada: os dois
 * caminhos que criam trade pelo price-researcher (lista comentada e prospecção)
 * passam por aqui, para a forma da linha mudar num lugar só.
 *
 * Transformação pura — o bundle chega já resolvido no mapa, porque descobri-lo
 * é consulta ao banco e não cabe no Domain.
 */
final class TradeLineBuilder
{
    /**
     * @param  array<int, array{name: string, price_euro: float|string, popularity: int|string, region?: string|null, gamivo_id?: string|null}>  $games
     * @param  array<string, string>  $bundleMap  nome normalizado do jogo → nome do bundle; vazio quando o caller não resolve bundle
     * @return array<int, array<string, mixed>> atributos de `trade_lines`, sem o vínculo com a trade
     */
    public static function fromResearch(array $games, array $bundleMap): array
    {
        $lines = [];

        // foreach, não array_map com contador: arrow function captura por valor,
        // e todo mundo sairia na posição 0.
        foreach (array_values($games) as $position => $game) {
            $lines[] = [
                'position' => $position,
                'game_name' => TradeLineValue::text($game['name']),
                // O preço chega numérico do pesquisador; fixar 2 casas aqui
                // mantém a linha nova idêntica à convertida do JSON.
                'market_price' => TradeLineValue::decimal(number_format((float) $game['price_euro'], 2, '.', '')),
                'popularity' => TradeLineValue::integer($game['popularity']),
                'region' => TradeLineValue::text($game['region'] ?? null),
                'bundle' => $bundleMap[GameNameNormalizer::normalize($game['name'])] ?? null,
                'expires_at' => null,
                'key_code' => null,
                'gamivo_id' => TradeLineValue::text($game['gamivo_id'] ?? null),
            ];
        }

        return $lines;
    }
}
