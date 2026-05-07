<?php

namespace App\UseCases\Marketplaces\Gamivo;

use App\Domain\Pricing\IncomeCalculator;
use App\Services\External\GamivoApiService;
use App\Services\Keys\KeyCalculationService;
use App\Services\Keys\KeyRepository;
use Illuminate\Support\Facades\Log;

/**
 * Atualiza keys vendidas com dados de venda da API Gamivo.
 *
 * Dois modos de uso:
 *  - execute(array $soldGames)       — recebe dados já processados (chamada via HTTP do legado Node.js)
 *  - executeFromGamivo(int $days)    — busca autonomamente no histórico da API Gamivo (cron diário)
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
     * Executado diariamente às 7h via scheduler.
     *
     * @param  int  $lookbackDays  Janela de busca em dias (padrão: 30 para cobrir vendas não processadas)
     * @return array<int, mixed> Keys que falharam na atualização
     */
    public function executeFromGamivo(int $lookbackDays = 30): array
    {
        $filters = [
            'dateFrom' => now()->subDays($lookbackDays)->toDateString(),
            'dateTo' => now()->toDateString(),
            'statuses' => ['COMPLETED'],
        ];

        $soldGames = [];
        $offset = 0;

        do {
            $page = $this->gamivoApi->getSalesHistory($filters, $offset);

            foreach ($page as $sale) {
                $processed = $this->processSale($sale);

                if ($processed !== null) {
                    $soldGames[] = $processed;
                }
            }

            $offset += 25;
        } while (count($page) === 25);

        if (empty($soldGames)) {
            Log::info('UpdateSoldOffersUseCase: nenhuma venda encontrada no período', $filters);

            return [];
        }

        Log::info('UpdateSoldOffersUseCase: vendas encontradas', ['total' => count($soldGames)]);

        return $this->execute($soldGames);
    }

    // ── Modo externo (legado Node.js via HTTP) ────────────────────────────────

    /**
     * Recebe vendas já processadas e dá baixa nas keys correspondentes.
     * Chamado diretamente pelo endpoint POST /keys/update-sold-offers.
     *
     * @param  array<int, array{keys: string[], profit: numeric, saleDate: string}>  $soldGames
     * @return array<int, mixed> Keys que falharam na atualização
     */
    public function execute(array $soldGames): array
    {
        $notUpdated = [];

        foreach ($soldGames as $game) {
            foreach ($game['keys'] as $keyCode) {
                $key = $this->keyRepository->findByKeyCode($keyCode);

                if (! $key || $key->sold_price) {
                    continue;
                }

                $saleFormulas = $this->calculationService->calculateSaleFormulas(
                    (float) $game['profit'],
                    (float) $key->individual_cost,
                );

                $updated = $key->update([
                    'sold_at' => $game['saleDate'],
                    'sold_price' => $game['profit'],
                    'sale_profit' => $saleFormulas['sale_profit'],
                    'sale_profit_percent' => $saleFormulas['sale_profit_percent'],
                ]);

                if (! $updated) {
                    $notUpdated[] = $key;
                }
            }
        }

        return $notUpdated;
    }

    // ── Privados ──────────────────────────────────────────────────────────────

    /**
     * Converte uma entrada do histórico de vendas da Gamivo no formato esperado por execute().
     * Retorna null se o pedido não tiver keys de texto válidas.
     */
    private function processSale(array $sale): ?array
    {
        // profit = valor líquido recebido (profit + seller_tax - taxa de intermediação)
        $profit = (float) $sale['profit'] + (float) ($sale['seller_tax'] ?? 0) - IncomeCalculator::MEDIATION_FEE;

        // created_at chega no formato não-padrão "2025-04-13UTC17:44:480"
        $saleDate = explode('UTC', $sale['created_at'])[0];

        $orderDetails = $this->gamivoApi->getSaleOrderDetails($sale['order_id']);

        if ($orderDetails === null || empty($orderDetails['keys'])) {
            Log::warning("UpdateSoldOffersUseCase: sem detalhes para order_id={$sale['order_id']}");

            return null;
        }

        // A chave do objeto 'keys' é o offer_id (string) — não o product_name (bug do Node.js)
        $keys = [];
        foreach ($orderDetails['keys'] as $offerEntry) {
            foreach ($offerEntry['keys'] ?? [] as $keyEntry) {
                if (($keyEntry['type'] ?? '') === 'TEXT' && ! empty($keyEntry['key'])) {
                    $keys[] = $keyEntry['key'];
                }
            }
        }

        if (empty($keys)) {
            return null;
        }

        // Dividir lucro igualmente entre as keys do pedido
        $perKeyProfit = round($profit / count($keys), 2);

        return [
            'keys' => $keys,
            'profit' => $perKeyProfit,
            'saleDate' => $saleDate,
        ];
    }
}
