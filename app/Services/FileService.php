<?php

namespace App\Services;

use App\Http\Helpers\Formulas;
use App\Models\Fornecedor;
use App\Models\Venda_chave_troca;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Illuminate\Support\Facades\Validator;

class FileService
{
    protected $formulas;
    protected $calculateService;
    protected $gameService;

    public function __construct()
    {
        $this->formulas = new Formulas();
        $this->calculateService = new CalculateService();
        $this->gameService = new GameService();
    }

    private $requiredColumns = [
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
     * Valida e processa o arquivo XLSX
     */
    public function validateAndProcess($filePath)
    {
        try {
            DB::beginTransaction();
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();

            // Valida cabeçalhos
            $validationHeaders = $this->validateHeaders($worksheet);
            if (!$validationHeaders['success']) {
                return ['success' => false, 'message' => $validationHeaders['message'], 'errors' => []];
            }

            // Extrai e valida dados
            $extractedData = $this->extractData($worksheet);

            // Insere os jogos no banco de dados
            $result = $this->storeKeys($extractedData);

            DB::commit();
            return [
                'success' => true,
                'data' => $result['games'],
                'message' => $result['message'],
                'errors' => []
            ];
        } catch (\Exception $e) {
            // Log detalhado do erro
            Log::error('Erro ao importar arquivo XLSX', [
                'file_path' => $filePath,
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
                'error_line' => $e->getLine(),
                'error_file' => $e->getFile(),
            ]);

            DB::rollBack();

            return [
                'success' => false,
                'data' => [],
                'message' => '',
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
            return ['success' => false, 'message' => 'Cabeçalhos inválidos: ' . implode(', ', $errors)];
        }

        return ['success' => true, 'message' => 'Cabeçalhos válidos'];
    }

    /**
     * Armazena as chaves extraídas do Excel (similar ao método store do controller)
     */
    private function storeKeys($games)
    {
        $fullGames = [];
        $errors = [];

        try {
            // Calcula as fórmulas iniciais
            $resultFirstFormulas = $this->calculateFirstFormulas($games);
            $games = $resultFirstFormulas['games'];
            $somatorioIncomes = $resultFirstFormulas['somatorioIncomes'];

            foreach ($games as $index => $game) {
                try {
                    // Criar/obter fornecedor
                    $game['id_fornecedor'] = $this->criarAdicionarFornecedor($game['perfilOrigem'], $game['tipo_reclamacao_id']);

                    // Calcula as fórmulas
                    $game = $this->calculateFormulas($game, $somatorioIncomes, false);

                    // Verifica se o jogo é repetido
                    $repeatedGame = Venda_chave_troca::where('chaveRecebida', $game['chaveRecebida'])->first();
                    if ($repeatedGame) $game['repetido'] = true;

                    // Identifica a plataforma
                    $game['plataformaIdentificada'] = $this->identifyPlatform($game['chaveRecebida']);

                    // Calcula min/max da API
                    $game = $this->calculateService->calculateMinMaxApi($game);

                    // Limpa o nome do jogo
                    $game['nomeJogo'] = trim($game['nomeJogo']);

                    // Busca ID do Gamivo se não tiver
                    if (empty($game['idGamivo'])) {
                        $idGamivo = $this->gameService->getIdGamivo($game['nomeJogo'], $game['region']);
                        if ($idGamivo) {
                            $game['idGamivo'] = $idGamivo;
                        }
                    }

                    // Preenche o ID do Gamivo na tabela de games
                    if (!empty($game['idGamivo'])) {
                        $this->gameService->fillIdGamivo($game['nomeJogo'], $game['region'], $game['idGamivo']);
                    }

                    // Cadastra o jogo na tabela Games se ainda não estiver
                    $this->gameService->createGameIfDontExists($game);

                    // Define o mínimo para venda se não estiver definido
                    if (empty($game['minimoParaVenda'])) {
                        $game['minimoParaVenda'] = $game['precoCliente'] * 1.05;
                    }

                    // Inserir o valor pago total no padrão
                    if (empty($game['valorPagoTotal'])) {
                        $game['valorPagoTotal'] = $game['qtdTF2'] . "x TF2 Keys / " . count($games);
                    }

                    // Cria o registro no banco
                    $created = Venda_chave_troca::create($game);

                    if ($created) {
                        // Busca o jogo completo com relacionamentos
                        $fullGame = Venda_chave_troca::where('id', $created->id)
                            ->with([
                                'fornecedor',
                                'tipoReclamacao',
                                'tipoFormato',
                                'leilaoG2A',
                                'leilaoGamivo',
                                'leilaoKinguin',
                                'plataforma'
                            ])
                            ->first();

                        $fullGames[] = $fullGame;

                        Log::info("Jogo importado com sucesso", [
                            'id' => $created->id,
                            'nome' => $game['nomeJogo'],
                            'chave' => substr($game['chaveRecebida'], 0, 10) . '...' // Log parcial da chave por segurança
                        ]);
                    }
                } catch (\Exception $e) {
                    // Log do erro para este jogo específico
                    Log::error("Erro ao importar jogo na linha " . ($index + 2), [
                        'game_data' => $game,
                        'error_message' => $e->getMessage(),
                        'error_trace' => $e->getTraceAsString(),
                    ]);

                    $errors[] = [
                        'linha' => $index + 2,
                        'jogo' => $game['nomeJogo'] ?? 'Desconhecido',
                        'erro' => $e->getMessage()
                    ];
                }
            }

            // Verifica se há jogos com plataforma não identificada
            $hasUnidentified = array_filter($fullGames, function ($game) {
                return isset($game['plataformaIdentificada']) && $game['plataformaIdentificada'] === "DESCONHECIDO";
            });

            $message = 'Jogos cadastrados com sucesso';
            if (!empty($hasUnidentified)) {
                $message = 'Jogos cadastrados com sucesso, mas ' . count($hasUnidentified) . ' jogo(s) com plataforma não identificada.';
            }

            if (!empty($errors)) {
                $message .= ' Com ' . count($errors) . ' erro(s).';
            }

            return [
                'games' => $fullGames,
                'message' => $message,
                'errors' => $errors,
                'total_imported' => count($fullGames),
                'total_errors' => count($errors),
            ];
        } catch (\Exception $e) {
            Log::error('Erro crítico no storeKeys', [
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
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
                'dataAdquirida' => $this->convertExcelDate($worksheet->getCell('B' . $row)),

                // Região
                'region' => trim($worksheet->getCell('I' . $row)->getValue() ?? null),

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
                'dataExpiracao' => $this->convertExcelDate($worksheet->getCell('G' . $row)),
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
            Log::error('Erros de validação nas linhas do Excel', ['errors' => $errors]);
            throw new \Exception('Erros de validação: ' . json_encode($errors, JSON_UNESCAPED_UNICODE));
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
            'region' => 'nullable|string|max:50',
            'perfilOrigem' => 'required|string|max:255',
            'qtdTF2' => 'required|numeric|min:0',
            'dataAdquirida' => 'nullable|string',
        ], [
            'nomeJogo.required' => "Linha {$rowNumber}: Nome do jogo é obrigatório",
            'chaveRecebida.required' => "Linha {$rowNumber}: Chave recebida é obrigatória",
            'perfilOrigem.required' => "Linha {$rowNumber}: URL do perfil (coluna D) é obrigatória",
            'qtdTF2.required' => "Linha {$rowNumber}: Quantidade de TF2 Keys (coluna E) é obrigatória",
            'qtdTF2.numeric' => "Linha {$rowNumber}: Quantidade de TF2 Keys deve ser numérica",
        ]);
    }

    /**
     * Retorna o caminho do arquivo de exemplo
     */
    public static function getExampleFilePath()
    {
        return public_path('assets/example/import_keys.xlsx');
    }

    // ==================== MÉTODOS AUXILIARES ====================

    /**
     * Calcula as fórmulas iniciais (preço venda, income simulado, income real)
     */
    private function calculateFirstFormulas($games)
    {
        $somatorioIncomes = 0;
        foreach ($games as &$game) {
            $game['precoVenda'] = $this->formulas->calcPrecoVenda(
                $game['tipo_formato_id'],
                $game['id_plataforma'],
                $game['precoCliente']
            );

            $game['incomeSimulado'] = $this->formulas->calcIncomeSimulado(
                $game['tipo_formato_id'],
                $game['id_plataforma'],
                $game['precoCliente'],
                $game['precoVenda']
            );

            $game['incomeReal'] = $this->formulas->calcIncomeReal(
                $game['tipo_formato_id'],
                $game['id_plataforma'],
                $game['precoCliente'],
                $game['precoVenda'],
                $game['leiloes'],
                $game['quantidade']
            );

            $somatorioIncomes += $game['incomeSimulado'];
        }

        return [
            'games' => $games,
            'somatorioIncomes' => $somatorioIncomes
        ];
    }

    /**
     * Calcula fórmulas de lucro e classificações
     */
    private function calculateFormulas($game, $somatorioIncomes, $isEdit = false)
    {
        if (!$isEdit) {
            $game['valorPagoIndividual'] = $this->formulas->calcValorPagoIndividual(
                $game['qtdTF2'],
                $somatorioIncomes,
                $game['incomeSimulado']
            );

            $game['lucroRS'] = $this->formulas->calcLucroReal(
                $game['incomeSimulado'],
                $game['valorPagoIndividual']
            );

            $game['lucroPercentual'] = $this->formulas->calcLucroPercentual(
                $game['lucroRS'],
                $game['valorPagoIndividual']
            );
        }

        $game['lucroVendaRS'] = $this->formulas->calcLucroVendaReal(
            $game['valorVendido'],
            $game['valorPagoIndividual']
        );

        $game['lucroVendaPercentual'] = $this->formulas->calcLucroVendaPercentual(
            $game['lucroVendaRS'],
            $game['valorPagoIndividual']
        );

        $game['randomClassificationG2A'] = $this->formulas->classificacaoRandomG2A(
            $game['precoJogo'],
            $game['notaMetacritic']
        );

        $game['randomClassificationKinguin'] = $this->formulas->classificacaoRandomKinguin(
            $game['precoJogo'],
            $game['notaMetacritic']
        );

        return $game;
    }

    /**
     * Cria ou adiciona reclamação ao fornecedor
     */
    private function criarAdicionarFornecedor($perfilOrigem, $reclamacao)
    {
        $fornecedor = Fornecedor::where('perfilOrigem', $perfilOrigem)->first();

        if (!$fornecedor) {
            // Se não tiver o fornecedor, cria ele
            $newFornecedor = ['perfilOrigem' => $perfilOrigem];

            if ($reclamacao != 1) {
                $newFornecedor['quantidade_reclamacoes'] = 1;
            }

            $fornecedor = Fornecedor::create($newFornecedor);
        } else {
            // Existe o fornecedor, soma mais uma reclamação se tiver
            if ($reclamacao != 1) {
                $fornecedor->where('perfilOrigem', $perfilOrigem)
                    ->update(['quantidade_reclamacoes' => $fornecedor->quantidade_reclamacoes + 1]);
            }
        }

        return $fornecedor->id;
    }

    /**
     * Identifica a plataforma do jogo baseado no padrão da chave
     */
    private function identifyPlatform($chaveRecebida)
    {
        $patterns = [
            'Steam' => '/^\w{5}-\w{5}-\w{5}$|^\w{15}\s\w{2}$/',
            'EA' => '/^\w{4}-\w{4}-\w{4}-\w{4}-\w{4}$/',
            'EA/Ubisoft' => '/^\w{4}-\w{4}-\w{4}-\w{4}$/',
            'EGS' => '/^\w{5}-\w{5}-\w{5}-\w{5}$/',
            'GOG' => '/^\w{18}$/',
            'XBOX' => '/^\w{5}-\w{5}-\w{5}-\w{5}-\w{5}$/',
            'PSN' => '/^\w{4}-\w{4}-\w{4}$/',
        ];

        foreach ($patterns as $platform => $pattern) {
            if (preg_match($pattern, $chaveRecebida)) {
                return $platform;
            }
        }

        return 'DESCONHECIDO';
    }

    /**
     * Converte datas do Excel (serial number) para formato Y-m-d
     * Excel armazena datas como números (dias desde 01/01/1900)
     * Exemplo: 45955 = 25/10/2025
     */
    private function convertExcelDate($cell)
    {
        $value = $cell->getValue();

        // Se estiver vazio, retorna null
        if (empty($value)) return null;

        // Se for um número (serial date do Excel)
        if (is_numeric($value)) {
            try {
                // Converte o serial number do Excel para DateTime
                $dateTime = ExcelDate::excelToDateTimeObject($value);
                return $dateTime->format('Y-m-d');
            } catch (\Exception $e) {
                Log::warning('Erro ao converter data do Excel', [
                    'value' => $value,
                    'error' => $e->getMessage()
                ]);
                return now()->toDateString();
            }
        }

        // Se já for uma string de data, tenta converter
        if (is_string($value)) {
            try {
                // Tenta parsear a data (suporta vários formatos)
                $dateTime = \DateTime::createFromFormat('d/m/Y', $value)
                    ?: \DateTime::createFromFormat('Y-m-d', $value)
                    ?: \DateTime::createFromFormat('d-m-Y', $value);

                if ($dateTime) {
                    return $dateTime->format('Y-m-d');
                }
            } catch (\Exception $e) {
                Log::warning('Erro ao parsear data string', [
                    'value' => $value,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Se nada funcionou, retorna a data de hoje
        return now()->toDateString();
    }
}
