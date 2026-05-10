<?php

namespace App\Domain\Import;

/**
 * Valida os cabeçalhos de uma planilha de importação de keys.
 *
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
        'A' => 'Data',
        'B' => 'Gamivo',
        'C' => 'URL perfil',
        'D' => 'Qtd. TF2',
        'E' => 'Bundle',
        'F' => 'Data expiração',
        'G' => 'Popularidade',
        'H' => 'Region Lock',
        'I' => 'Chave',
        'J' => 'Nome do Jogo',
    ];

    /**
     * Valida os cabeçalhos recebidos contra os esperados.
     *
     * @param  array<string, string>  $actual  Cabeçalhos extraídos da planilha (coluna → valor lido)
     * @return array<string> Lista de mensagens de erro; vazia se válido
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
