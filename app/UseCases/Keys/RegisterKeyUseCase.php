<?php

namespace App\UseCases\Keys;

use App\Domain\Platform\PlatformIdentifier;
use App\Domain\Pricing\SalePriceCalculator;
use App\Models\Venda_chave_troca;
use App\Services\Games\GameService;
use App\Services\Keys\KeyCalculationService;
use App\Services\Keys\KeyRepository;
use App\Services\Suppliers\SupplierService;
use Illuminate\Support\Facades\Log;

/**
 * Orquestra o registro de um lote de keys.
 *
 * Responsabilidade única: coordenar a criação de múltiplas keys,
 * chamando os Services e Domain corretos em ordem.
 *
 * Entrada : array de primitivos já validados (Form Request ou XLSX).
 * Saída   : array com games persistidos, mensagem e erros por linha.
 */
class RegisterKeyUseCase
{
    public function __construct(
        private readonly KeyCalculationService $calculationService,
        private readonly SupplierService $supplierService,
        private readonly GameService $gameService,
        private readonly KeyRepository $keyRepository,
    ) {}

    /**
     * Registra um lote de keys no banco de dados.
     *
     * Erros por key são coletados e retornados sem interromper o lote.
     * Erros catastróficos (ex: banco indisponível) propagam exceções.
     *
     * @param  array<int, array<string, mixed>>  $games
     * @return array{games: list<Venda_chave_troca>, message: string, errors: list<array>}
     */
    public function execute(array $games): array
    {
        $fullGames = [];
        $errors = [];

        // Passo 1 — calcula incomeSimulado por key e acumula o somatório do lote
        $firstFormulas = $this->calculationService->calculateFirstFormulas($games);
        $games = $firstFormulas['games'];
        $somatorioIncomes = $firstFormulas['somatorioIncomes'];

        $totalGames = count($games);

        foreach ($games as $index => $game) {
            try {
                // Resolve fornecedor (cria se necessário)
                $game['id_fornecedor'] = $this->supplierService->findOrCreate($game['perfilOrigem']);

                // Calcula lucros de compra
                $game = $this->calculationService->calculateFormulas($game, $somatorioIncomes, false);

                // Verifica duplicidade
                if ($this->keyRepository->findByKeyCode($game['chaveRecebida'])) {
                    $game['repetido'] = true;
                }

                // Identifica plataforma pelo padrão da chave (Domain — sem dependência de infra)
                $game['plataformaIdentificada'] = PlatformIdentifier::identify($game['chaveRecebida']);

                // Calcula min/max da API Gamivo
                $game = $this->calculationService->calculateMinMaxApi($game);

                // Normaliza nome do jogo
                $game['nomeJogo'] = trim($game['nomeJogo']);

                // Busca idGamivo externo se ainda não tiver
                if (empty($game['idGamivo'])) {
                    $idGamivo = $this->gameService->getIdGamivo($game['nomeJogo'], $game['region']);
                    if ($idGamivo) {
                        $game['idGamivo'] = $idGamivo;
                    }
                }

                // Propaga idGamivo para a tabela games
                if (! empty($game['idGamivo'])) {
                    $this->gameService->fillIdGamivo($game['nomeJogo'], $game['region'], $game['idGamivo']);
                }

                // Cadastra o jogo na tabela games se ainda não existir
                $this->gameService->createGameIfDontExists($game);

                $game['minimoParaVenda'] = SalePriceCalculator::minimumSalePrice((float) $game['precoCliente']);

                $game['valorPagoTotal'] = SalePriceCalculator::tradeCostLabel((float) $game['qtdTF2'], $totalGames);

                // Remove campos de lucro de venda nulos antes de persistir.
                // O banco tem DEFAULT 0 para esses campos — a semântica "não vendida"
                // já é capturada por dataVendida IS NULL.
                if (($game['lucroVendaRS'] ?? null) === null) {
                    unset($game['lucroVendaRS']);
                }
                if (($game['lucroVendaPercentual'] ?? null) === null) {
                    unset($game['lucroVendaPercentual']);
                }

                // Persiste e carrega com relacionamentos
                $created = Venda_chave_troca::create($game);
                $fullGames[] = $created->load(['fornecedor']);

                Log::info('Key registrada com sucesso', [
                    'id' => $created->id,
                    'nome' => $game['nomeJogo'],
                ]);
            } catch (\Throwable $e) {
                Log::error('Erro ao registrar key', [
                    'indice' => $index + 1,
                    'nome' => $game['nomeJogo'] ?? 'Desconhecido',
                    'erro' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $errors[] = [
                    'linha' => $index + 1,
                    'jogo' => $game['nomeJogo'] ?? 'Desconhecido',
                    'erro' => $e->getMessage(),
                ];
            }
        }

        return [
            'games' => $fullGames,
            'message' => $this->buildMessage($fullGames, $errors),
            'errors' => $errors,
        ];
    }

    /**
     * Constrói a mensagem de retorno com base nos resultados do lote.
     *
     * @param  list<Venda_chave_troca>  $fullGames
     * @param  list<array>  $errors
     */
    private function buildMessage(array $fullGames, array $errors): string
    {
        $hasUnidentified = array_filter(
            $fullGames,
            fn ($g) => ($g->plataformaIdentificada ?? null) === 'DESCONHECIDO',
        );

        $message = 'Jogos cadastrados com sucesso';

        if (! empty($hasUnidentified)) {
            $message .= ', mas '.count($hasUnidentified).' jogo(s) com plataforma não identificada';
        }

        if (! empty($errors)) {
            $message .= '. Com '.count($errors).' erro(s)';
        }

        return $message;
    }
}
