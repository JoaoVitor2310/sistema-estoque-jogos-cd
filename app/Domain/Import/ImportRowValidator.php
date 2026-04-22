<?php

namespace App\Domain\Import;

/**
 * Define as regras de validação para cada linha da planilha de importação.
 *
 * PHP puro — as REGRAS vivem aqui; a execução da validação usa o Validator
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
        'nomeJogo' => 'required|string|max:255',
        'chaveRecebida' => 'required|string',
        'region' => 'nullable|string|max:50',
        'perfilOrigem' => 'required|string|max:255',
        'qtdTF2' => 'required|numeric|min:0.01',
        'dataAdquirida' => 'nullable|string',
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
            'nomeJogo.required' => "Linha {$rowNumber}: Nome do jogo é obrigatório",
            'chaveRecebida.required' => "Linha {$rowNumber}: Chave recebida é obrigatória",
            'perfilOrigem.required' => "Linha {$rowNumber}: URL do perfil (coluna D) é obrigatória",
            'qtdTF2.required' => "Linha {$rowNumber}: Quantidade de TF2 (coluna E) é obrigatória",
            'qtdTF2.numeric' => "Linha {$rowNumber}: Quantidade de TF2 deve ser numérica",
            'qtdTF2.min' => "Linha {$rowNumber}: Quantidade de TF2 deve ser pelo menos 0.01 (para evitar divisão por zero)",
        ];
    }
}
