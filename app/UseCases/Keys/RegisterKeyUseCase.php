<?php

namespace App\UseCases\Keys;

use App\Domain\Keys\KeyDefaults;
use App\Domain\Platform\PlatformIdentifier;
use App\Domain\Pricing\SalePriceCalculator;
use App\Models\Key;
use App\Models\Trade;
use App\Services\Games\GameService;
use App\Services\Keys\KeyCalculationService;
use App\Services\Keys\KeyRepository;
use App\Services\Suppliers\SupplierService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orquestra o registro de um lote de keys a partir de uma Trade.
 *
 * Responsabilidade única: coordenar a criação de múltiplas keys,
 * chamando os Services e Domain corretos em ordem.
 *
 * Toda key nasce de uma trade — a importação por trade
 * (`POST /trades/{trade}/import`) é o único caminho de entrada de keys no
 * sistema. As keys são vinculadas à trade (`trade_id`) e, terminando sem erros,
 * a trade é marcada como importada; ela permanece no banco para o vínculo seguir
 * válido (ver docs/adr/0004). A coluna `keys.trade_id` é nullable apenas por
 * causa das keys anteriores a esse vínculo.
 *
 * A importação é **atômica**: ou todas as keys do lote são registradas, ou
 * nenhuma é. Qualquer erro descarta o lote inteiro (inclusive os efeitos
 * colaterais em `games`/`suppliers`) e devolve a lista completa de erros, para
 * o usuário corrigir tudo de uma vez em vez de reimportar em partes.
 *
 * Entrada : Trade de origem + array de primitivos já validados.
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
     * Registra um lote de keys no banco de dados, de forma atômica.
     *
     * Todas as keys são avaliadas para que os erros do lote sejam reportados de
     * uma vez só; havendo qualquer erro, nada é persistido.
     * Erros catastróficos (ex: banco indisponível) propagam exceções.
     *
     * @param  Trade  $trade  Trade de origem do lote — obrigatória: toda key pertence a uma trade.
     * @param  array<int, array<string, mixed>>  $games
     * @return array{games: list<Key>, message: string, errors: list<array>}
     */
    public function execute(Trade $trade, array $games): array
    {
        $fullGames = [];
        $errors = [];

        // Aplica defaults de domínio — campos ausentes recebem o valor canônico;
        // campos explicitamente fornecidos pelo caller prevalecem.
        $games = array_map(
            fn (array $game) => array_merge(KeyDefaults::toArray(), $game),
            $games,
        );

        // Passo 1 — calcula simulated_income por key e acumula o somatório do lote
        $firstFormulas = $this->calculationService->calculateFirstFormulas($games);
        $games = $firstFormulas['games'];
        $somatorioIncomes = $firstFormulas['somatorioIncomes'];

        $totalGames = count($games);

        DB::beginTransaction();

        foreach ($games as $index => $game) {
            try {
                // Savepoint por key: uma falha de banco numa key desfaz só essa key,
                // mantendo a transação externa utilizável para avaliar as demais —
                // é o que permite reportar todos os erros do lote de uma vez.
                $created = DB::transaction(
                    fn () => $this->registerKey($game, $trade, $somatorioIncomes, $totalGames),
                );

                $fullGames[] = $created->load(['supplier']);
            } catch (\Throwable $e) {
                $errors[] = [
                    'line' => $index + 1,
                    'game' => $game['game_name'] ?? 'Desconhecido',
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Tudo ou nada: qualquer erro descarta o lote inteiro. O rollback desfaz
        // também os efeitos colaterais em `games`/`suppliers`, evitando resíduos.
        if (! empty($errors)) {
            DB::rollBack();

            return [
                'games' => [],
                'message' => $this->buildMessage([], $errors),
                'errors' => $errors,
            ];
        }

        // Lote íntegro: marca a trade como importada. Ela não é excluída — as keys
        // a referenciam via trade_id.
        $trade->update(['is_imported' => true]);

        DB::commit();

        Log::info('Lote de keys registrado', [
            'trade_id' => $trade->id,
            'total' => count($fullGames),
        ]);

        return [
            'games' => $fullGames,
            'message' => $this->buildMessage($fullGames, []),
            'errors' => [],
        ];
    }

    /**
     * Monta e persiste uma única key do lote.
     *
     * @param  array<string, mixed>  $game
     */
    private function registerKey(array $game, Trade $trade, float $somatorioIncomes, int $totalGames): Key
    {
        // Toda key nasce vinculada à trade de origem
        $game['trade_id'] = $trade->id;

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

        // Busca steam_id existente se ainda não tiver
        if (empty($game['steam_id'])) {
            $steamId = $this->gameService->getSteamId($game['game_name'], $game['region']);
            if ($steamId) {
                $game['steam_id'] = $steamId;
            }
        }

        // Propaga steam_id para a tabela games
        if (! empty($game['steam_id'])) {
            $this->gameService->fillSteamId($game['game_name'], $game['region'], $game['steam_id']);
        }

        // Cadastra o jogo na tabela games se ainda não existir
        $this->gameService->createGameIfDontExists($game);

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

        return Key::create($game);
    }

    /**
     * Constrói a mensagem de retorno com base nos resultados do lote.
     *
     * @param  list<Key>  $fullGames
     * @param  list<array>  $errors
     */
    private function buildMessage(array $fullGames, array $errors): string
    {
        // Importação atômica: com erros, nada foi cadastrado.
        if (! empty($errors)) {
            return 'Nenhuma key foi cadastrada — corrija '.count($errors).' erro(s) e importe novamente';
        }

        $hasUnidentified = array_filter(
            $fullGames,
            fn ($g) => ($g->identified_platform ?? null) === 'DESCONHECIDO',
        );

        $message = 'Jogos cadastrados com sucesso';

        if (! empty($hasUnidentified)) {
            $message .= ', mas '.count($hasUnidentified).' jogo(s) com plataforma não identificada';
        }

        return $message;
    }
}
