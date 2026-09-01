<?php

namespace App\UseCases\Marketplaces\Gamivo;

use App\Domain\Enums\OrderPayoutAttribution;
use App\Domain\Pricing\OrderPayoutSplitter;
use App\Services\External\GamivoApiService;
use App\Services\Keys\KeyCalculationService;
use App\Services\Keys\KeyRepository;
use App\UseCases\Marketplaces\Gamivo\DTO\OrderPayoutBreakdownDTO;
use Illuminate\Support\Facades\Log;

/**
 * Atualiza keys vendidas com dados de venda da API Gamivo.
 *
 * Dois métodos:
 *  - executeFromGamivo(int $days)    — busca no histórico da API Gamivo e delega ao execute() (cron 6h e 18h)
 *  - execute(array $soldGames)       — núcleo: aplica dados de venda já processados às keys
 */
class UpdateSoldOffersUseCase
{
    public function __construct(
        private readonly GamivoApiService $gamivoApi,
        private readonly KeyRepository $keyRepository,
        private readonly KeyCalculationService $calculationService,
    ) {}

    // ── Modo autônomo (cron) ──────────────────────────────────────────────────

    /**
     * Busca o histórico de vendas da Gamivo e dá baixa nas keys correspondentes.
     *
     * @param  int  $lookbackDays  Janela de busca em dias (padrão: 30 para cobrir vendas não processadas)
     * @return array<int, mixed> Keys que falharam na atualização
     */
    public function executeFromGamivo(int $lookbackDays = 30): array
    {
        $orders = $this->fetchOrders(
            now()->subDays($lookbackDays)->toDateString(),
            now()->toDateString(),
        );
        $breakdowns = $this->breakdownsFor($orders);

        $soldGames = [];

        // Um pedido pode gerar baixas e mesmo assim ter caído no rateio igual:
        // sem o contador por atribuição o resumo não distingue os dois casos
        $attributions = array_fill_keys(
            array_column(OrderPayoutAttribution::cases(), 'value'),
            0,
        );

        foreach ($breakdowns as $breakdown) {
            $attributions[$breakdown->attribution->value]++;
            $soldGames = array_merge($soldGames, $breakdown->entries);
        }

        $result = $this->execute($soldGames);

        Log::channel('schedulers')->info('UpdateSoldOffersUseCase', [
            'lookback_days' => $lookbackDays,
            'sale_lines' => array_sum(array_map('count', $orders)),
            'orders_found' => count($orders),
            'orders_by_attribution' => $attributions,
            'keys_updated' => $result['updated'],
            'keys_failed' => count($result['failed']),
            'keys_skipped' => $result['skipped'],
            'updated_keys' => $result['updatedKeys'],
            'failed_details' => $result['failed'],
        ]);

        return $result['failed'];
    }

    /**
     * Recalcula, pedido a pedido, quanto cada key deveria ter recebido — **sem gravar nada**.
     *
     * Existe para o backfill conferir o que está no banco contra o que a Gamivo diz hoje.
     * Usa exatamente o mesmo caminho do cron, então o que ele mostra é o que o cron gravaria.
     *
     * @param  string  $dateFrom  data inicial no formato Y-m-d
     * @param  string  $dateTo  data final no formato Y-m-d
     * @param  callable|null  $onOrder  recebe (processados, total) a cada pedido
     * @return array<string, OrderPayoutBreakdownDTO> indexado por order_id
     */
    public function reconcileFromGamivo(string $dateFrom, string $dateTo, ?callable $onOrder = null): array
    {
        return $this->breakdownsFor($this->fetchOrders($dateFrom, $dateTo), $onOrder);
    }

    /**
     * Recalcula pedidos específicos, sem varrer a janela inteira — **sem gravar nada**.
     *
     * O filtro `order` da API devolve só as linhas daquele pedido, então cada um custa
     * duas chamadas em vez de exigir a paginação completa do histórico. É o que torna
     * viável corrigir em produção uma lista já conhecida.
     *
     * @param  string[]  $orderIds
     * @param  callable|null  $onOrder  recebe (processados, total) a cada pedido
     * @return array<string, OrderPayoutBreakdownDTO> indexado por order_id
     */
    public function reconcileOrders(array $orderIds, ?callable $onOrder = null): array
    {
        $orders = [];

        foreach ($orderIds as $orderId) {
            $rows = $this->gamivoApi->getSalesHistory(['order' => $orderId]);

            if ($rows !== []) {
                $orders[$orderId] = $rows;
            }
        }

        return $this->breakdownsFor($orders, $onOrder);
    }

    // ── Núcleo — aplica dados de venda já processados ──────────────────────────

    /**
     * Recebe vendas já processadas e dá baixa nas keys correspondentes.
     * Usado pelo modo autônomo (executeFromGamivo) após montar os dados de venda.
     *
     * @param  array<int, array{keys: string[], profit: numeric, saleDate: string}>  $soldGames
     * @return array{updated: int, skipped: int, failed: array<int, mixed>}
     */
    public function execute(array $soldGames): array
    {
        $updated = 0;
        $skipped = 0;
        $failed = [];
        $updatedKeys = [];

        foreach ($soldGames as $game) {
            foreach ($game['keys'] as $keyCode) {
                $key = $this->keyRepository->findByKeyCode($keyCode);

                // Key não encontrada ou já registrada como vendida (idempotência)
                if (! $key || $key->sold_price) {
                    $skipped++;

                    continue;
                }

                $saleFormulas = $this->calculationService->calculateSaleFormulas(
                    (float) $game['profit'],
                    (float) $key->individual_cost,
                );

                $wasUpdated = $key->update([
                    'sold_at' => $game['saleDate'],
                    'sold_price' => $game['profit'],
                    'sale_profit' => $saleFormulas['sale_profit'],
                    'sale_profit_percent' => $saleFormulas['sale_profit_percent'],
                ]);

                if ($wasUpdated) {
                    $updated++;
                    $updatedKeys[] = [
                        'key_code' => $key->key_code,
                        'game_name' => $key->game_name,
                    ];
                } else {
                    $failed[] = [
                        'key_code' => $key->key_code,
                        'game_name' => $key->game_name,
                    ];
                }
            }
        }

        return compact('updated', 'skipped', 'failed', 'updatedKeys');
    }

    // ── Privados ──────────────────────────────────────────────────────────────

    /**
     * Agrupa as linhas do histórico pelo pedido a que pertencem.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<int, array<string, mixed>>>
     */
    private static function groupByOrder(array $rows): array
    {
        $orders = [];

        foreach ($rows as $row) {
            $orderId = (string) ($row['order_id'] ?? '');

            // A paginação da Gamivo é por offset e o histórico cresce enquanto ela é
            // percorrida: uma venda nova empurra as linhas e a mesma pode ser lida em
            // duas páginas. Como uma oferta é única por produto, duas linhas com o
            // mesmo product_id no mesmo pedido são a mesma linha repetida — e mantê-las
            // duplica o bruto do pedido, distribuindo dinheiro que não existe.
            $orders[$orderId][(string) ($row['product_id'] ?? '')] = $row;
        }

        return array_map(array_values(...), $orders);
    }

    /**
     * Busca o histórico da Gamivo na janela e agrupa as linhas por pedido.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function fetchOrders(string $dateFrom, string $dateTo): array
    {
        $filters = [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'statuses' => ['COMPLETED'],
        ];

        $rows = [];
        $offset = 0;

        do {
            $page = $this->gamivoApi->getSalesHistory($filters, $offset);
            $rows = array_merge($rows, $page);
            $offset += 25;
        } while (count($page) === 25);

        // A Gamivo devolve uma linha por oferta vendida: as linhas de um mesmo pedido
        // só fazem sentido juntas, porque as keys entregues e a taxa de mediação são
        // do pedido inteiro. Um pedido pode ainda vir partido entre duas páginas.
        return self::groupByOrder($rows);
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $orders
     * @return array<string, OrderPayoutBreakdownDTO>
     */
    private function breakdownsFor(array $orders, ?callable $onOrder = null): array
    {
        $breakdowns = [];

        foreach ($orders as $orderId => $rows) {
            $breakdowns[(string) $orderId] = $this->processOrder((string) $orderId, $rows);

            // Cada pedido custa uma chamada de order-details: numa janela de anos
            // a passada leva minutos, e quem chamou precisa poder mostrar andamento
            if ($onOrder !== null) {
                $onOrder(count($breakdowns), count($orders));
            }
        }

        return $breakdowns;
    }

    /**
     * Converte as linhas de histórico de um pedido nas entradas esperadas por execute().
     *
     * Cada linha traz o profit da sua própria oferta; as keys entregues vêm de outro
     * endpoint, agrupadas por offer_id — que o histórico não expõe. O casamento é
     * feito pelo product_id da linha, que é o id que a key guarda em gamivo_id.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function processOrder(string $orderId, array $rows): OrderPayoutBreakdownDTO
    {
        $orderDetails = $this->gamivoApi->getSaleOrderDetails($orderId);

        if ($orderDetails === null || empty($orderDetails['keys'])) {
            Log::channel('schedulers')->warning("UpdateSoldOffersUseCase: sem detalhes para order_id={$orderId}");

            return new OrderPayoutBreakdownDTO([], OrderPayoutAttribution::NoOrderDetails);
        }

        $keyCodes = self::extractKeyCodes($orderDetails);

        if ($keyCodes === []) {
            Log::channel('schedulers')->warning("UpdateSoldOffersUseCase: order_id={$orderId} não entregou key de texto");

            return new OrderPayoutBreakdownDTO([], OrderPayoutAttribution::NoTextKeys);
        }

        [$grossByKey, $dateByKey, $attribution] = $this->attributeGross($orderId, $rows, $keyCodes);

        $entries = [];

        foreach (OrderPayoutSplitter::split($grossByKey) as $keyCode => $net) {
            $entries[] = [
                'keys' => [$keyCode],
                'profit' => $net,
                'saleDate' => $dateByKey[$keyCode],
            ];
        }

        return new OrderPayoutBreakdownDTO($entries, $attribution);
    }

    /**
     * Atribui a cada key entregue o bruto da oferta de onde ela veio, casando o
     * product_id da linha com o gamivo_id da key e consumindo a quantidade vendida.
     *
     * O casamento é linha a linha, não tudo-ou-nada: uma key desconhecida na base não
     * tira das outras o valor da própria oferta. Só o que sobrou sem par — linha sem
     * key e key sem linha — é dividido por igual entre si. Quando sobra linha mas não
     * sobra key para recebê-la, o bruto dela não teria onde pousar; nesse caso o pedido
     * inteiro volta ao rateio igual, que ao menos preserva o total recebido.
     *
     * Key sem linha correspondente fica de fora: não gravar é retentável na próxima
     * passada, gravar um valor adivinhado é definitivo (execute() nunca sobrescreve).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  string[]  $keyCodes
     * @return array{0: array<string, float>, 1: array<string, string>, 2: OrderPayoutAttribution}
     */
    private function attributeGross(string $orderId, array $rows, array $keyCodes): array
    {
        /** @var array<string, string[]> $available product_id => keys entregues daquele produto */
        $available = [];

        /** @var string[] $keysWithoutRow keys que nenhuma linha reclamou */
        $keysWithoutRow = [];

        $keys = $this->keyRepository->findByKeyCodes($keyCodes);

        foreach ($keyCodes as $keyCode) {
            $productId = (string) ($keys->get($keyCode)?->gamivo_id ?? '');

            if ($productId === '') {
                $keysWithoutRow[] = $keyCode;

                continue;
            }

            $available[$productId][] = $keyCode;
        }

        $grossByKey = [];
        $dateByKey = [];
        $rowsWithoutKey = [];
        $matchedRows = 0;

        foreach ($rows as $row) {
            $productId = (string) ($row['product_id'] ?? '');
            $quantity = max(1, (int) ($row['quantity'] ?? 1));

            if (count($available[$productId] ?? []) < $quantity) {
                $rowsWithoutKey[] = $row;

                continue;
            }

            $rowKeys = array_splice($available[$productId], 0, $quantity);
            $share = self::grossOf([$row]) / count($rowKeys);
            $matchedRows++;

            foreach ($rowKeys as $keyCode) {
                $grossByKey[$keyCode] = $share;
                $dateByKey[$keyCode] = self::saleDate($row);
            }
        }

        foreach ($available as $leftover) {
            array_push($keysWithoutRow, ...$leftover);
        }

        if ($rowsWithoutKey === [] && $keysWithoutRow === []) {
            return [$grossByKey, $dateByKey, OrderPayoutAttribution::Matched];
        }

        if ($keysWithoutRow === []) {
            self::logFallback($orderId, count($rowsWithoutKey).' linha(s) sem key para receber o valor');

            $share = self::grossOf($rows) / count($keyCodes);

            return [
                array_fill_keys($keyCodes, $share),
                array_fill_keys($keyCodes, self::saleDate($rows[array_key_first($rows)])),
                OrderPayoutAttribution::EqualSplit,
            ];
        }

        if ($rowsWithoutKey !== []) {
            $share = self::grossOf($rowsWithoutKey) / count($keysWithoutRow);
            $saleDate = self::saleDate($rowsWithoutKey[0]);

            foreach ($keysWithoutRow as $keyCode) {
                $grossByKey[$keyCode] = $share;
                $dateByKey[$keyCode] = $saleDate;
            }
        }

        self::logFallback($orderId, count($rowsWithoutKey).' linha(s) e '.count($keysWithoutRow).' key(s) sem par');

        return [
            $grossByKey,
            $dateByKey,
            $matchedRows > 0
                ? OrderPayoutAttribution::PartiallyMatched
                : OrderPayoutAttribution::EqualSplit,
        ];
    }

    /** Registra por que parte de um pedido caiu no rateio igual. */
    private static function logFallback(string $orderId, string $reason): void
    {
        Log::channel('schedulers')->warning("UpdateSoldOffersUseCase: rateio igual no order_id={$orderId} — {$reason}");
    }

    /**
     * Achata as keys de texto entregues no pedido.
     * A chave do objeto 'keys' do order-details é o offer_id (string), não o product_name.
     *
     * @param  array<string, mixed>  $orderDetails
     * @return string[]
     */
    private static function extractKeyCodes(array $orderDetails): array
    {
        $keyCodes = [];

        foreach ($orderDetails['keys'] as $offerEntry) {
            foreach ($offerEntry['keys'] ?? [] as $keyEntry) {
                if (($keyEntry['type'] ?? '') === 'TEXT' && ! empty($keyEntry['key'])) {
                    $keyCodes[] = $keyEntry['key'];
                }
            }
        }

        return $keyCodes;
    }

    /**
     * Bruto recebido nas linhas informadas: profit mais o imposto que a Gamivo repassa.
     * A taxa de mediação não entra aqui — é descontada uma vez por pedido no rateio.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private static function grossOf(array $rows): float
    {
        return array_sum(array_map(
            fn (array $row) => (float) ($row['profit'] ?? 0) + (float) ($row['seller_tax'] ?? 0),
            $rows,
        ));
    }

    /**
     * Data da venda. O created_at chega no formato não-padrão "2025-04-13UTC17:44:480".
     *
     * @param  array<string, mixed>  $row
     */
    private static function saleDate(array $row): string
    {
        return explode('UTC', (string) ($row['created_at'] ?? ''))[0];
    }
}
