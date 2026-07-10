<?php

namespace App\Domain\Games;

/**
 * Normaliza nomes de jogos para matching de inventário entre o
 * price-researcher e o estoque local (bundle lookup, dedupe de games).
 *
 * Trata apenas diferenças de formatação (pontuação, casing, algarismos
 * romanos) — nunca remove sufixos de edição/DLC, pois "Cyberpunk 2077" e
 * "Cyberpunk 2077 Deluxe Edition" são produtos distintos no estoque.
 *
 * Espelha o pipeline `clearString` do price-researcher para que os dois lados produzam a
 * mesma string normalizada a partir do mesmo nome de jogo.
 */
final class GameNameNormalizer
{
    public static function normalize(string $name): string
    {
        $name = preg_replace('/\/mx\b/i', '', $name);

        $name = preg_replace_callback(
            '/\b(?!dlc\b)[IVXLCDM]+\b/i',
            fn (array $m) => (string) self::romanToInt(strtoupper($m[0])),
            $name
        );

        $name = preg_replace('/-\s?/', '', $name);
        $name = mb_strtolower($name);
        $name = preg_replace('/[™:®!?().\[\]{}&*+^;]/u', '', $name);
        $name = preg_replace('/\|\s*/', '', $name);
        $name = preg_replace("/['']\s*/u", '', $name);
        $name = preg_replace('/\bthe\b\s*/i', '', $name);
        $name = str_replace(',', '', $name);

        $name = preg_replace_callback(
            '/\b(\d+(?:\.\d+)?)k\b/i',
            fn (array $m) => (string) ((float) $m[1] * 1000),
            $name
        );

        $name = preg_replace('/\bx\d+\b|\b\d+x\b/i', '', $name);
        $name = preg_replace('/\s{2,}/', ' ', $name);

        return trim($name);
    }

    private static function romanToInt(string $roman): int
    {
        $map = ['M' => 1000, 'D' => 500, 'C' => 100, 'L' => 50, 'X' => 10, 'V' => 5, 'I' => 1];
        $result = 0;
        $len = strlen($roman);

        for ($i = 0; $i < $len; $i++) {
            $current = $map[$roman[$i]] ?? 0;
            $next = $map[$roman[$i + 1] ?? ''] ?? 0;

            if ($current < $next) {
                $result -= $current;
            } else {
                $result += $current;
            }
        }

        return $result;
    }
}
