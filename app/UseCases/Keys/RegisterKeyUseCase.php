<?php

namespace App\UseCases\Keys;

use App\Domain\Platform\PlatformIdentifier;
use App\Domain\Pricing\SalePriceCalculator;
use App\Models\Key;
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
     * @return array{games: list<Key>, message: string, errors: list<array>}
     */
    public function execute(array $games): array
    {
        $fullGames = [];
        $errors = [];

        // Passo 1 — calcula simulated_income por key e acumula o somatório do lote
        $firstFormulas = $this->calculationService->calculateFirstFormulas($games);
        $games = $firstFormulas['games'];
        $somatorioIncomes = $firstFormulas['somatorioIncomes'];

        $totalGames = count($games);

        foreach ($games as $index => $game) {
            try {
                // Resolve fornecedor (cria se necessário)
                $game['supplier_id'] = $this->supplierService->findOrCreate($game['supplier_url']);

                // Calcula lucros de compra
                $game = $this->calculationService->calculateFormulas($game, $somatorioIncomes, false);

                // Verifica duplicidade
                if ($this->keyRepository->findByKeyCode($game['key_code'])) {
                    $game['is_duplicate'] = true;
                }

                // Identifica plataforma pelo padrão da chave (Domain — sem dependência de infra)
                $game['identified_platform'] = PlatformIdentifier::identify($game['key_code']);

                // Calcula min/max da API Gamivo
                $game = $this->calculationService->calculateMinMaxApi($game);

                // Normaliza nome do jogo
                $game['game_name'] = trim($game['game_name']);

                // Busca gamivo_id externo se ainda não tiver
                if (empty($game['gamivo_id'])) {
                    $gamivoId = $this->gameService->getIdGamivo($game['game_name'], $game['region']);
                    if ($gamivoId) {
                        $game['gamivo_id'] = $gamivoId;
                    }
                }

                // Propaga gamivo_id para a tabela games
                if (! empty($game['gamivo_id'])) {
                    $this->gameService->fillIdGamivo($game['game_name'], $game['region'], $game['gamivo_id']);
                }

                // Cadastra o jogo na tabela games se ainda não existir
                $this->gameService->createGameIfDontExists($game);

                $game['minimum_sale_price'] = SalePriceCalculator::minimumSalePrice((float) $game['market_price']);

                $game['total_paid'] = SalePriceCalculator::tradeCostLabel((float) $game['tf2_quantity'], $totalGames);

                // Remove campos de lucro de venda nulos antes de persistir.
                // O banco tem DEFAULT 0 para esses campos — a semântica "não vendida"
                // já é capturada por sold_at IS NULL.
                if (($game['sale_profit'] ?? null) === null) {
                    unset($game['sale_profit']);
                }
                if (($game['sale_profit_percent'] ?? null) === null) {
                    unset($game['sale_profit_percent']);
                }

                // Persiste e carrega com relacionamentos
                $created = Key::create($game);
                $fullGames[] = $created->load(['supplier']);

                Log::info('Key registrada com sucesso', [
                    'id' => $created->id,
                    'nome' => $game['game_name'],
                ]);
            } catch (\Throwable $e) {
                Log::error('Erro ao registrar key', [
                    'indice' => $index + 1,
                    'nome' => $game['game_name'] ?? 'Desconhecido',
                    'erro' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $errors[] = [
                    'linha' => $index + 1,
                    'jogo' => $game['game_name'] ?? 'Desconhecido',
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
     * @param  list<Key>  $fullGames
     * @param  list<array>  $errors
     */
    private function buildMessage(array $fullGames, array $errors): string
    {
        $hasUnidentified = array_filter(
            $fullGames,
            fn ($g) => ($g->identified_platform ?? null) === 'DESCONHECIDO',
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
