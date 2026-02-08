<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Validator;

class FileService
{
    private $requiredColumns = [
        'A' => 'Nome do Jogo',
        'B' => 'Chave Recebida',
        'C' => 'Região',
        'D' => 'Preço Cliente',
        'E' => 'Perfil Origem',
        // Adicione outras colunas conforme necessário
    ];

    /**
     * Valida e processa o arquivo XLSX
     */
    public function validateAndProcess($filePath)
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            
            // Valida cabeçalhos
            $this->validateHeaders($worksheet);
            
            // Extrai e valida dados
            $data = $this->extractData($worksheet);
            
            return [
                'success' => true,
                'data' => $data,
                'errors' => []
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'data' => [],
                'errors' => [$e->getMessage()]
            ];
        }
    }

    /**
     * Valida se os cabeçalhos estão corretos
     */
    private function validateHeaders($worksheet)
    {
        $errors = [];
        
        foreach ($this->requiredColumns as $column => $expectedName) {
            $cellValue = $worksheet->getCell($column . '1')->getValue();
            
            if (trim($cellValue) !== $expectedName) {
                $errors[] = "Coluna {$column} deveria ser '{$expectedName}', mas encontrou '{$cellValue}'";
            }
        }
        
        if (!empty($errors)) {
            throw new \Exception('Cabeçalhos inválidos: ' . implode(', ', $errors));
        }
    }

    /**
     * Extrai dados das células
     */
    private function extractData($worksheet)
    {
        $data = [];
        $highestRow = $worksheet->getHighestRow();
        $errors = [];
        
        for ($row = 2; $row <= $highestRow; $row++) {
            $rowData = [
                'nomeJogo' => trim($worksheet->getCell('A' . $row)->getValue()),
                'chaveRecebida' => trim($worksheet->getCell('B' . $row)->getValue()),
                'region' => trim($worksheet->getCell('C' . $row)->getValue()),
                'precoCliente' => $worksheet->getCell('D' . $row)->getValue(),
                'perfilOrigem' => trim($worksheet->getCell('E' . $row)->getValue()),
                // Adicione outros campos
            ];
            
            // Valida cada linha
            $validator = $this->validateRow($rowData, $row);
            
            if ($validator->fails()) {
                $errors[] = [
                    'linha' => $row,
                    'erros' => $validator->errors()->all()
                ];
            } else {
                $data[] = $rowData;
            }
        }
        
        if (!empty($errors)) {
            throw new \Exception('Erros nas linhas: ' . json_encode($errors));
        }
        
        return $data;
    }

    /**
     * Valida uma linha específica
     */
    private function validateRow($rowData, $rowNumber)
    {
        return Validator::make($rowData, [
            'nomeJogo' => 'required|string|max:255',
            'chaveRecebida' => 'required|string',
            'region' => 'required|string|max:10',
            'precoCliente' => 'required|numeric|min:0',
            'perfilOrigem' => 'required|string|max:255',
        ], [
            'nomeJogo.required' => "Linha {$rowNumber}: Nome do jogo é obrigatório",
            'chaveRecebida.required' => "Linha {$rowNumber}: Chave recebida é obrigatória",
            'region.required' => "Linha {$rowNumber}: Região é obrigatória",
            'precoCliente.required' => "Linha {$rowNumber}: Preço do cliente é obrigatório",
            'precoCliente.numeric' => "Linha {$rowNumber}: Preço do cliente deve ser numérico",
        ]);
    }

    /**
     * Retorna o caminho do arquivo de exemplo
     */
    public static function getExampleFilePath()
    {
        return public_path('assets/example/import_keys.xlsx');
    }
}