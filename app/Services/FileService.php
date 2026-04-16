<?php

namespace App\Services;

use App\Domain\Import\ExcelDateConverter;
use App\Domain\Import\ImportHeaderValidator;
use App\Domain\Import\ImportRowValidator;
use App\UseCases\Keys\RegisterKeyUseCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Validator;

class FileService
{
    public function __construct(protected RegisterKeyUseCase $registerKeyUseCase)
    {}


    /**
     * Valida e processa o arquivo XLSX
     */
    public function validateAndProcess($filePath): array
    {
        try {
            DB::beginTransaction();

            $spreadsheet = IOFactory::load($filePath);
            $worksheet   = $spreadsheet->getActiveSheet();

            // Valida cabeçalhos
            $validationHeaders = $this->validateHeaders($worksheet);
            if (!$validationHeaders['success']) {
                return ['success' => false, 'message' => $validationHeaders['message'], 'errors' => []];
            }

            // Extrai e valida linhas
            $extractedData = $this->extractData($worksheet);

            // Registra o lote via UseCase (orquestra cálculos, fornecedor, gamivo, etc.)
            $result = $this->registerKeyUseCase->execute($extractedData);

            DB::commit();

            return [
                'success' => true,
                'data'    => $result['games'],
                'message' => $result['message'],
                'errors'  => $result['errors'],
            ];
        } catch (\Exception $e) {
            Log::error('Erro ao importar arquivo XLSX', [
                'file_path'     => $filePath,
                'error_message' => $e->getMessage(),
                'error_trace'   => $e->getTraceAsString(),
                'error_line'    => $e->getLine(),
                'error_file'    => $e->getFile(),
            ]);

            DB::rollBack();

            return [
                'success' => false,
                'data'    => [],
                'message' => '',
                'errors'  => [$e->getMessage()],
            ];
        }
    }

    /**
     * Valida se os cabeçalhos estão corretos.
     * Extrai os valores do worksheet e delega a validação ao Domain.
     */
    private function validateHeaders($worksheet): array
    {
        $actual = [];
        foreach (array_keys(ImportHeaderValidator::EXPECTED_COLUMNS) as $column) {
            $actual[$column] = $worksheet->getCell($column . '1')->getValue();
        }

        $errors = ImportHeaderValidator::validate($actual);

        if (!empty($errors)) {
            return ['success' => false, 'message' => 'Cabeçalhos inválidos: ' . implode(', ', $errors)];
        }

        return ['success' => true, 'message' => 'Cabeçalhos válidos'];
    }

    /**
     * Extrai dados das células baseado no layout do Excel
     * Colunas: A=G2A, B=Data, C=Gamivo, D=URL perfil, E=Qtd. TF2, 
     *          F=Bundle, G=Data expiração, H=Popularidade, 
     *          I=Region Lock, J=Chave, K=Nome do Jogo
     */
    private function extractData($worksheet)
    {
        $data = [];
        $highestRow = $worksheet->getHighestRow();
        $errors = [];

        for ($row = 2; $row <= $highestRow; $row++) {
            // Pula linhas vazias
            if (empty($worksheet->getCell('J' . $row)->getValue())) {
                continue;
            }

            $rowData = [
                // Campos obrigatórios
                'chaveRecebida' => trim($worksheet->getCell('J' . $row)->getValue() ?? ''),
                'nomeJogo' => trim($worksheet->getCell('K' . $row)->getValue() ?? ''),
                'perfilOrigem' => trim($worksheet->getCell('D' . $row)->getValue() ?? ''),
                'qtdTF2' => floatval(str_replace(',', '.', $worksheet->getCell('E' . $row)->getValue() ?? '0')),
                'dataAdquirida' => ExcelDateConverter::convert($worksheet->getCell('B' . $row)->getValue()) ?? now()->toDateString(),

                // Região (string vazia do Excel vira null)
                'region' => trim($worksheet->getCell('I' . $row)->getValue() ?? '') ?: null,

                // IDs e referências
                'idGamivo' => null,
                'steamId' => null,

                // Valores e configurações
                'precoCliente' => $worksheet->getCell('C' . $row)->getValue() ? trim($worksheet->getCell('C' . $row)->getValue()) : null,
                'precoJogo' => null,
                'notaMetacritic' => 0,
                'minimoParaVenda' => null,
                'minApiGamivo' => null,
                'maxApiGamivo' => null,

                // IDs de configuração (valores padrão)
                'tipo_reclamacao_id' => 1, // Sem reclamação
                'tipo_formato_id' => 1, // Padrão
                'id_leilao_g2a' => intval($worksheet->getCell('A' . $row)->getValue() ?? 1),
                'id_leilao_gamivo' => 1,
                'id_leilao_kinguin' => 1,
                'id_plataforma' => 3, // Gamivo por padrão

                // Datas opcionais
                'dataExpiracao' => ExcelDateConverter::convert($worksheet->getCell('G' . $row)->getValue()),
                'dataVenda' => null,
                'dataVendida' => null,

                // Outros campos
                'observacao' => null,
                'chaveEntregue' => null,
                'valorPagoTotal' => null,
                'vendido' => false,
                'leiloes' => 1,
                'quantidade' => 1,
                'devolucoes' => false,
                'valorVendido' => null,
                'email' => null,
                'isSteam' => false,
                'color' => null,
            ];

            // Valida cada linha
            $validator = Validator::make($rowData, ImportRowValidator::RULES, ImportRowValidator::messages($row));

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
            Log::error('Erros de validação nas linhas do Excel', ['errors' => $errors]);
            throw new \Exception('Erros de validação: ' . json_encode($errors, JSON_UNESCAPED_UNICODE));
        }

        return $data;
    }

    /**
     * Retorna o caminho do arquivo de exemplo
     */
    public static function getExampleFilePath()
    {
        return public_path('assets/example/import_keys.xlsx');
    }

}
