<?php

namespace App\UseCases\Keys;

use App\Domain\Import\ExcelDateConverter;
use App\Domain\Import\ImportHeaderValidator;
use App\Domain\Import\ImportRowValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Orquestra a importação de keys a partir de um arquivo XLSX.
 *
 * Responsabilidades:
 *  - Carregar o arquivo (PhpSpreadsheet — infraestrutura de IO)
 *  - Validar cabeçalhos via Domain (ImportHeaderValidator)
 *  - Extrair e validar linhas via Domain (ImportRowValidator, ExcelDateConverter)
 *  - Registrar o lote via RegisterKeyUseCase
 *  - Gerenciar a transação que envolve todo o fluxo
 *
 * Layout do XLSX:
 *   A=acquired_at   B=market_price   C=supplier_url   D=tf2_quantity
 *   E=Bundle        F=expires_at     G=Popularidade   H=region
 *   I=key_code      J=game_name
 */
class ImportKeysFromXlsxUseCase
{
    public function __construct(
        private readonly RegisterKeyUseCase $registerKeyUseCase,
    ) {}

    /**
     * @param  string  $filePath  Caminho absoluto do arquivo XLSX
     * @return array{success: bool, data: array, message: string, errors: array}
     */
    public function execute(string $filePath): array
    {
        try {
            DB::beginTransaction();

            $worksheet = IOFactory::load($filePath)->getActiveSheet();

            // Valida cabeçalhos via Domain — falha rápida antes de qualquer leitura de dados
            $headerErrors = ImportHeaderValidator::validate($this->readHeaders($worksheet));
            if (! empty($headerErrors)) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Cabeçalhos inválidos: '.implode(', ', $headerErrors),
                    'data' => [],
                    'errors' => $headerErrors,
                ];
            }

            // Extrai e valida linhas via Domain — lança exceção se alguma linha for inválida
            $games = $this->extractRows($worksheet);

            // Registra o lote — erros por key são coletados, não interrompem o lote
            $result = $this->registerKeyUseCase->execute($games);

            DB::commit();

            return [
                'success' => true,
                'data' => $result['games'],
                'message' => $result['message'],
                'errors' => $result['errors'],
            ];
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Erro ao importar arquivo XLSX', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'data' => [],
                'message' => '',
                'errors' => [$e->getMessage()],
            ];
        }
    }

    /**
     * Lê os valores da linha de cabeçalho e retorna no formato esperado pelo Domain.
     *
     * @return array<string, mixed>
     */
    private function readHeaders(Worksheet $worksheet): array
    {
        $headers = [];
        foreach (array_keys(ImportHeaderValidator::EXPECTED_COLUMNS) as $column) {
            $headers[$column] = $worksheet->getCell($column.'1')->getValue();
        }

        return $headers;
    }

    /**
     * Percorre as linhas do worksheet, mapeia cada célula para o array de dados
     * e valida via Domain (ImportRowValidator).
     *
     * Lança exceção se qualquer linha falhar na validação — o caller faz rollback.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws \Exception Quando uma ou mais linhas falham na validação
     */
    private function extractRows(Worksheet $worksheet): array
    {
        $games = [];
        $errors = [];

        for ($row = 2; $row <= $worksheet->getHighestRow(); $row++) {
            // Pula linhas sem chave (coluna I)
            if (empty($worksheet->getCell('I'.$row)->getValue())) {
                continue;
            }

            $data = [
                'key_code' => trim($worksheet->getCell('I'.$row)->getValue() ?? ''),
                'game_name' => trim($worksheet->getCell('J'.$row)->getValue() ?? ''),
                'supplier_url' => trim($worksheet->getCell('C'.$row)->getValue() ?? ''),
                'tf2_quantity' => floatval(str_replace(',', '.', $worksheet->getCell('D'.$row)->getValue() ?? '0')),
                'acquired_at' => ExcelDateConverter::convert($worksheet->getCell('A'.$row)->getValue()) ?? now()->toDateString(),
                'region' => trim($worksheet->getCell('H'.$row)->getValue() ?? '') ?: null,
                'market_price' => $worksheet->getCell('B'.$row)->getValue()
                    ? trim($worksheet->getCell('B'.$row)->getValue())
                    : null,
                'expires_at' => ExcelDateConverter::convert($worksheet->getCell('F'.$row)->getValue()),

                // Defaults — campos não presentes no XLSX recebem valores padrão
                'gamivo_id' => null,
                'steamId' => null,
                'precoJogo' => null,
                'minimum_sale_price' => null,
                'minApiGamivo' => null,
                'maxApiGamivo' => null,
                'claim_type' => 'Nenhuma',
                'key_format' => 'RK',
                'sell_platform' => 'Gamivo',
                'listed_at' => null,
                'sold_at' => null,
                'observacao' => null,
                'total_paid' => null,
                'sold_price' => null,
                'email' => null,
                'color' => null,
            ];

            $validator = Validator::make($data, ImportRowValidator::RULES, ImportRowValidator::messages($row));

            if ($validator->fails()) {
                $errors[] = ['linha' => $row, 'erros' => $validator->errors()->all()];
            } else {
                $games[] = $data;
            }
        }

        if (! empty($errors)) {
            Log::warning('Erros de validação nas linhas do XLSX', ['errors' => $errors]);
            throw new \Exception('Erros de validação: '.json_encode($errors, JSON_UNESCAPED_UNICODE));
        }

        return $games;
    }
}
