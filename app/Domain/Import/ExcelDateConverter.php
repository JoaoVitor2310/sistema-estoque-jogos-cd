<?php

namespace App\Domain\Import;

/**
 * Converte valores de data do Excel para o formato Y-m-d.
 *
 * PHP puro — zero dependência do Laravel ou de bibliotecas de planilha.
 * O chamador é responsável por extrair o valor da célula antes de passar.
 *
 * Formatos suportados:
 *  - Numérico (serial date do Excel): dias desde 30/12/1899
 *  - String: d/m/Y, Y-m-d, d-m-Y
 *  - Vazio/nulo: retorna null
 *
 * Retorna null quando o valor está ausente ou não é conversível.
 * O Service chamador decide o fallback adequado para cada contexto.
 */
final class ExcelDateConverter
{
    /**
     * Base do serial date do Excel: 30/12/1899.
     * Equivale ao Unix timestamp negativo de 25569 dias antes de 01/01/1970.
     */
    private const EXCEL_UNIX_EPOCH_DIFF_DAYS = 25569;

    private const SECONDS_PER_DAY = 86400;

    /**
     * Formatos de string aceitos, em ordem de tentativa.
     */
    private const STRING_FORMATS = ['d/m/Y', 'Y-m-d', 'd-m-Y'];

    /**
     * Converte um valor de célula do Excel para Y-m-d.
     *
     * @param  mixed  $value  Valor extraído da célula (int, float, string ou null)
     * @return string|null Data no formato Y-m-d, ou null se ausente/inválido
     */
    public static function convert(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return self::convertSerial((float) $value);
        }

        if (is_string($value)) {
            return self::convertString(trim($value));
        }

        return null;
    }

    /**
     * Converte serial date do Excel para Y-m-d.
     * Fórmula: (serial - 25569) × 86400 = Unix timestamp.
     */
    private static function convertSerial(float $serial): ?string
    {
        // Serials negativos ou zero são inválidos
        if ($serial <= 0) {
            return null;
        }

        $timestamp = (int) (($serial - self::EXCEL_UNIX_EPOCH_DIFF_DAYS) * self::SECONDS_PER_DAY);

        return date('Y-m-d', $timestamp);
    }

    /**
     * Converte string de data nos formatos suportados para Y-m-d.
     */
    private static function convertString(string $value): ?string
    {
        foreach (self::STRING_FORMATS as $format) {
            $dateTime = \DateTime::createFromFormat($format, $value);

            if ($dateTime !== false) {
                return $dateTime->format('Y-m-d');
            }
        }

        return null;
    }
}
