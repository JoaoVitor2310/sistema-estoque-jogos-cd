<?php

namespace App\UseCases\Keys;

use App\Domain\Enums\TradeImportBlocker;
use App\Domain\Keys\KeyDefaults;
use App\Domain\Platform\PlatformIdentifier;
use App\Domain\Pricing\SalePriceCalculator;
use App\Domain\Trades\ImportReadinessPolicy;
use App\Models\Key;
use App\Models\Trade;
use App\Models\TradeLine;
use App\Services\Games\GameService;
use App\Services\Keys\KeyCalculationService;
use App\Services\Keys\KeyRepository;
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
 * **O lote sai inteiro da trade gravada**, não de um payload: as linhas, a data,
 * o supplier e a quantidade de TF2 são lidos aqui. Enquanto o `market_price`
 * vinha no corpo do request, quem chamasse a rota ditava o rateio de
 * `individual_cost` do lote inteiro (ver docs/adr/0004).
 *
 * A importação é **atômica**: ou todas as keys do lote são registradas, ou
 * nenhuma é. Qualquer erro descarta o lote inteiro (inclusive os efeitos
 * colaterais em `games`) e devolve a lista completa de erros, para o usuário
 * corrigir tudo de uma vez em vez de reimportar em partes.
 *
 * Entrada : Trade de origem.
 * Saída   : array com games persistidos, mensagem e erros por linha.
 */
class RegisterKeyUseCase
{
    public function __construct(
        private readonly KeyCalculationService $calculationService,
        private readonly GameService $gameService,
        private readonly KeyRepository $keyRepository,
    ) {}

    /**
     * Registra as keys de uma trade no banco de dados, de forma atômica.
     *
     * Todas as keys são avaliadas para que os erros do lote sejam reportados de
     * uma vez só; havendo qualquer erro, nada é persistido.
     * Erros catastróficos (ex: banco indisponível) propagam exceções.
     *
     * @param  Trade  $trade  Trade de origem do lote — obrigatória: toda key pertence a uma trade.
     * @return array{games: list<Key>, message: string, errors: list<array>}
     */
    public function execute(Trade $trade): array
    {
        $trade->loadMissing(['lines', 'supplier', 'bundle']);

        $blockers = ImportReadinessPolicy::blockers(
            $trade->lines->map(fn (TradeLine $line) => [
                'game_name' => $line->game_name,
                'market_price' => $line->market_price,
                'key_code' => $line->key_code,
            ])->all(),
            $trade->tf2_qty,
            $trade->purchase_channel,
            $trade->supplier?->url,
            $trade->bundle_id,
        );

        if ($blockers !== []) {
            return [
                'games' => [],
                'message' => $this->buildBlockedMessage($blockers),
                'errors' => [],
            ];
        }

        $fullGames = [];
        $errors = [];

        // Aplica defaults de domínio — as colunas da key que a linha da trade não
        // carrega (formato, tipo de reclamação, plataforma de venda) recebem o
        // valor canônico.
        $games = array_map(
            fn (TradeLine $line) => array_merge(KeyDefaults::toArray(), $this->toKeyInput($line, $trade)),
            $this->linesToImport($trade),
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
        // também os efeitos colaterais em `games`, evitando resíduos.
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
     * As linhas que viram key, na ordem de exibição.
     *
     * Linha em branco fica de fora: uma trade em negociação carrega rascunho
     * vazio, e importar um registro sem nome nem preço só sujaria o estoque.
     *
     * @return list<TradeLine>
     */
    private function linesToImport(Trade $trade): array
    {
        return $trade->lines
            ->filter(fn (TradeLine $line) => ImportReadinessPolicy::isFilled($line->game_name, $line->market_price))
            ->values()
            ->all();
    }

    /**
     * Traduz uma linha da trade nos campos da key que ela origina.
     *
     * Data, supplier e quantidade de TF2 são da trade, não da linha — o lote
     * inteiro compartilha os três, e é da quantidade de TF2 que sai o rateio de
     * `individual_cost`.
     *
     * @return array<string, mixed>
     */
    private function toKeyInput(TradeLine $line, Trade $trade): array
    {
        return [
            'game_name' => $line->game_name,
            'market_price' => $line->market_price,
            'key_code' => $line->key_code,
            'region' => $line->region,
            'gamivo_id' => $line->gamivo_id,
            'expires_at' => $line->expires_at?->format('Y-m-d'),
            'acquired_at' => $trade->date?->format('Y-m-d'),
            // Garantido pela ImportReadinessPolicy, que já recusou o lote sem ele.
            'tf2_quantity' => $trade->tf2_qty,
            // A origem legível da key em qualquer canal: URL do supplier, nome do
            // bundle ou "Gamivo". O supplier_id só existe na trade com fornecedor.
            'supplier_url' => $trade->purchase_channel->keySource($trade->supplier?->url, $trade->bundle?->name),
        ];
    }

    /**
     * Monta e persiste uma única key do lote.
     *
     * @param  array<string, mixed>  $game
     */
    private function registerKey(array $game, Trade $trade, float $somatorioIncomes, int $totalGames): Key
    {
        // Toda key nasce vinculada à trade de origem, e ao fornecedor dela quando
        // o canal tem um — o supplier já foi resolvido quando a trade foi preenchida.
        $game['trade_id'] = $trade->id;
        $game['supplier_id'] = $trade->supplier_id;

        // Calcula lucros de compra
        $game = $this->calculationService->calculateFormulas($game, $somatorioIncomes, false);

        // Verifica duplicidade
        if ($this->keyRepository->findByKeyCode($game['key_code'])) {
            $game['is_duplicate'] = true;
        }

        // Identifica plataforma pelo padrão da chave (Domain — sem dependência de infra)
        $game['identified_platform'] = PlatformIdentifier::identify($game['key_code']);

        // Calcula min/max da API Gamivo
        $game = $this->calculationService->calculateMinMaxApi($game, $trade->purchase_channel);

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
     * Mensagem do lote recusado antes de começar.
     *
     * Os impedimentos são condições da trade inteira, não de uma linha: nenhuma
     * key entrou, e o motivo sai como texto único do lote em vez de erro por
     * linha.
     *
     * @param  list<TradeImportBlocker>  $blockers
     */
    private function buildBlockedMessage(array $blockers): string
    {
        $reasons = array_map(fn (TradeImportBlocker $blocker) => match ($blocker) {
            TradeImportBlocker::NoFilledLine => 'nenhuma linha preenchida',
            TradeImportBlocker::MissingGameName => 'linha preenchida sem nome do jogo',
            TradeImportBlocker::MissingMarketPrice => 'linha preenchida sem preço de mercado',
            TradeImportBlocker::MissingKeyCode => 'linha preenchida sem key code',
            TradeImportBlocker::MissingTf2Quantity => 'trade sem quantidade de TF2',
            TradeImportBlocker::MissingSupplierUrl => 'trade sem fornecedor',
            TradeImportBlocker::MissingBundle => 'compra direta sem bundle',
        }, $blockers);

        return 'Nenhuma key foi cadastrada — '.implode('; ', $reasons);
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
