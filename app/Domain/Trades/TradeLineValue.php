<?php

namespace App\Domain\Trades;

/**
 * O que cada campo de uma linha de trade aceita, e no que ele vira.
 *
 * Definição única compartilhada pelas duas entradas de dado: a conversão do
 * JSON legado ([[LegacyTradeLine]]) e a escrita campo a campo de uma linha. Se
 * as duas normalizassem por conta própria, o mesmo valor digitado produziria
 * resultados diferentes conforme o caminho.
 *
 * Nenhum método rejeita: o que não couber na coluna tipada vira `null`. Os
 * campos chegam como texto livre, em gravações parciais e sucessivas sobre a
 * mesma linha — abortar transformaria "ainda em edição" em erro.
 */
final class TradeLineValue
{
    /**
     * String vazia e string só de espaços viram `null` — as duas significam
     * "não preenchido", e a coluna não deve guardar a diferença.
     */
    public static function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    public static function decimal(mixed $value): ?string
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        // Vírgula decimal nunca apareceu no dado convertido, mas o campo chega
        // como texto livre — aceitar é mais barato que perder o valor.
        $normalized = str_replace(',', '.', $text);

        return is_numeric($normalized) ? $normalized : null;
    }

    public static function integer(mixed $value): ?int
    {
        $text = self::text($value);

        if ($text === null || preg_match('/^\d+$/', $text) !== 1) {
            return null;
        }

        return (int) $text;
    }

    /**
     * A validade é escrita em `dd/mm/aaaa`, o formato usado na operação; a
     * forma ISO é aceita porque é o que o próprio banco devolve. Data
     * impossível (31/02) vira `null`.
     */
    public static function date(mixed $value): ?string
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $iso) === 1) {
            return checkdate((int) $iso[2], (int) $iso[3], (int) $iso[1]) ? $text : null;
        }

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $text, $parts) !== 1) {
            return null;
        }

        [, $day, $month, $year] = $parts;

        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
