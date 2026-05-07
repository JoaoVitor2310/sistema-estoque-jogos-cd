<?php

namespace App\Domain\Import;

/**
 * Define as regras de validação para cada linha da planilha de importação.
 *
 * As REGRAS vivem aqui; a execução da validação usa o Validator
 * do Laravel no FileService (infra), pois o framework já faz isso muito bem.
 *
 * Centralizar as regras no Domain garante:
 *  - Uma única fonte de verdade para o formato esperado
 *  - Testabilidade das regras sem precisar subir o framework
 *  - Facilidade de encontrar e alterar validações de importação
 */
final class ImportRowValidator
{
    /**
     * Regras de validação compatíveis com Laravel Validator::make().
     */
    public const RULES = [
        'game_name' => 'required|string|max:255',
        'key_code' => 'required|string',
        'region' => 'nullable|string|max:50',
        'supplier_url' => 'required|string|max:255',
        'tf2_quantity' => 'required|numeric|min:0.01',
        'acquired_at' => 'nullable|string',
    ];

    /**
     * Mensagens de erro humanizadas, parametrizadas pelo número da linha.
     *
     * @param  int  $rowNumber  Número da linha na planilha (para mensagens contextuais)
     * @return array<string, string>
     */
    public static function messages(int $rowNumber): array
    {
        return [
            'game_name.required' => "Linha {$rowNumber}: Nome do jogo é obrigatório",
            'key_code.required' => "Linha {$rowNumber}: Chave recebida é obrigatória",
            'supplier_url.required' => "Linha {$rowNumber}: URL do perfil (coluna D) é obrigatória",
            'tf2_quantity.required' => "Linha {$rowNumber}: Quantidade de TF2 (coluna E) é obrigatória",
            'tf2_quantity.numeric' => "Linha {$rowNumber}: Quantidade de TF2 deve ser numérica",
            'tf2_quantity.min' => "Linha {$rowNumber}: Quantidade de TF2 deve ser pelo menos 0.01 (para evitar divisão por zero)",
        ];
    }
}
