<?php

namespace App\Domain\Import;

/**
 * Valida os cabeçalhos de uma planilha de importação de keys.
 *
 * PHP puro — zero dependência do Laravel ou de bibliotecas de planilha.
 * O chamador extrai os valores das células e passa como array antes de chamar.
 *
 * As colunas esperadas são regra de negócio (formato do arquivo de importação),
 * por isso vivem aqui como constante do Domain.
 */
final class ImportHeaderValidator
{
    /**
     * Mapa de coluna → nome esperado no cabeçalho.
     * Qualquer mudança no layout do arquivo de importação deve ser feita aqui.
     */
    public const EXPECTED_COLUMNS = [
        'A' => 'G2A',
        'B' => 'Data',
        'C' => 'Gamivo',
        'D' => 'URL perfil',
        'E' => 'Qtd. TF2',
        'F' => 'Bundle',
        'G' => 'Data expiração',
        'H' => 'Popularidade',
        'I' => 'Region Lock',
        'J' => 'Chave',
        'K' => 'Nome do Jogo',
    ];

    /**
     * Valida os cabeçalhos recebidos contra os esperados.
     *
     * @param array<string, string> $actual Cabeçalhos extraídos da planilha (coluna → valor lido)
     * @return array<string>                Lista de mensagens de erro; vazia se válido
     */
    public static function validate(array $actual): array
    {
        $errors = [];

        foreach (self::EXPECTED_COLUMNS as $column => $expectedName) {
            $actualValue = trim($actual[$column] ?? '');

            if ($actualValue !== $expectedName) {
                $errors[] = "Coluna {$column} deveria ser '{$expectedName}', mas encontrou '{$actualValue}'";
            }
        }

        return $errors;
    }
}
