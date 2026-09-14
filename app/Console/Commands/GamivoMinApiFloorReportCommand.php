<?php

namespace App\Console\Commands;

use App\Domain\Enums\PurchaseChannel;
use App\Domain\Pricing\ComparisonAlgorithm;
use App\Domain\Pricing\MinimumMarginPolicy;
use App\Domain\Pricing\OfferData;
use App\Domain\Pricing\ValueObjects\MarketplaceFee;
use App\Models\Key;
use App\Services\External\GamivoApiService;
use App\Services\Keys\KeyCalculationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Diagnóstico read-only: para cada oferta ativa na Gamivo, roda o mesmo
 * ComparisonAlgorithm do UpdateOffersUseCase (sem aplicar o clamp de
 * min_api/max_api) e compara o preço "natural" que o algoritmo pediria
 * contra o min_api da key governante — para medir, em €, o quanto o piso
 * está segurando o preço acima do que a concorrência sugere.
 *
 * seller_price (Gamivo) e min_api/max_api (banco) já estão na mesma unidade —
 * ambos líquidos, pós-taxa (ver IncomeCalculator) — então a comparação é
 * direta, sem recalcular taxa.
 *
 * ⚠️  Chama a API Gamivo de produção: 1x GET /offers + 1x GET
 * /products/{id}/offers por produto ativo (só leitura, nenhum PUT/POST).
 * Ainda assim, não rodar sem autorização explícita do usuário — mesma regra
 * de qualquer chamada real à Gamivo (ver CLAUDE.md).
 */
class GamivoMinApiFloorReportCommand extends Command
{
    protected $signature = 'gamivo:min-api-floor-report
        {--json=storage/app/diagnostics/min-api-floor-report.json}
        {--limit= : Processa só os N primeiros produtos (para um teste rápido antes do run completo)}
        {--delay-ms=150 : Pausa entre chamadas GET /products/{id}/offers, para não martelar a API}';

    protected $description = 'Simula o ComparisonAlgorithm sem clamp para medir o gap entre o preço natural de mercado e o min_api (read-only)';

    /** Produtos ignorados, espelhando UpdateOffersUseCase::IGNORED_PRODUCT_IDS. */
    private const IGNORED_PRODUCT_IDS = [1767];

    /** Tolerância (€) para considerar o preço natural "abaixo do piso". */
    private const FLOOR_TOLERANCE = 0.01;

    public function __construct(
        private readonly GamivoApiService $gamivoApi,
        private readonly KeyCalculationService $keyCalculationService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $fee = $this->keyCalculationService->getMarketplaceFee();
        $sellerName = config('services.gamivo.seller_name');

        $this->info('Buscando ofertas ativas na Gamivo (GET /offers, somente leitura)...');
        $activeOffers = $this->gamivoApi->getActiveOffers();
        $this->info(count($activeOffers).' ofertas ativas encontradas.');

        $activeByProduct = collect($activeOffers)->keyBy(fn (array $o) => (int) $o['product_id']);
        $productIds = $activeByProduct->keys()
            ->reject(fn (int $id) => in_array($id, self::IGNORED_PRODUCT_IDS, true))
            ->values();

        if ($limit = $this->option('limit')) {
            $productIds = $productIds->take((int) $limit);
        }

        $governingKeys = $this->findGoverningKeys($productIds->all());
        $delayMs = (int) $this->option('delay-ms');

        $rows = collect();
        $unmatched = 0;
        $errors = 0;

        $bar = $this->output->createProgressBar($productIds->count());
        $bar->start();

        foreach ($productIds as $productId) {
            $bar->advance();

            $key = $governingKeys->get($productId);
            if ($key === null) {
                $unmatched++;

                continue;
            }

            try {
                $rows->push($this->analyzeProduct($productId, $activeByProduct->get($productId), $key, $sellerName, $fee));
            } catch (\Throwable $e) {
                $errors++;
                $this->newLine();
                $this->warn("Produto {$productId}: erro ao consultar concorrentes — {$e->getMessage()}");
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $bar->finish();
        $this->newLine(2);

        if ($unmatched > 0) {
            $this->warn("{$unmatched} oferta(s) ativa(s) sem key governante correspondente no banco (ex: vendida mas oferta ainda não desativada).");
        }
        if ($errors > 0) {
            $this->warn("{$errors} produto(s) falharam ao consultar concorrentes e foram pulados.");
        }

        $jsonPath = $this->option('json');
        $this->writeJson($rows, $jsonPath);
        $this->printSummary($rows);

        return self::SUCCESS;
    }

    /**
     * Roda o ComparisonAlgorithm (mesma chamada do UpdateOffersUseCase) para um produto
     * e monta a linha de diagnóstico comparando o preço natural contra o min_api.
     */
    private function analyzeProduct(int $productId, array $activeOffer, Key $key, string $sellerName, MarketplaceFee $fee): array
    {
        $rawOffers = $this->gamivoApi->getOffersForProduct($productId);
        $offers = array_map(fn ($o) => OfferData::fromArray($o), $rawOffers);
        $result = ComparisonAlgorithm::calculate($offers, $sellerName, $fee);

        $currentSellerPrice = (float) $activeOffer['seller_price'];
        $minApi = (float) $key->min_api;
        $maxApi = (float) $key->max_api;

        // Sem ação do algoritmo (já somos os melhores ou não há concorrentes) — não há
        // pressão de preço, então o "natural" é o preço já praticado.
        $naturalSellerPrice = $result->shouldUpdate ? $result->sellerPrice : $currentSellerPrice;

        $gapBelowFloor = round($minApi - $naturalSellerPrice, 2);
        $isFloorBound = $result->shouldUpdate && $gapBelowFloor > self::FLOOR_TOLERANCE;
        $isCeilingBound = $result->shouldUpdate && round($naturalSellerPrice - $maxApi, 2) > self::FLOOR_TOLERANCE;

        return [
            'gamivo_id' => $productId,
            'game_name' => $key->game_name,
            'reason' => $result->reason,
            'current_seller_price' => $currentSellerPrice,
            'current_retail_price' => (float) ($activeOffer['retail_price'] ?? 0),
            'natural_seller_price' => round($naturalSellerPrice, 2),
            'natural_retail_price' => $result->shouldUpdate ? round($result->targetRetail, 2) : null,
            'min_api' => $minApi,
            'max_api' => $maxApi,
            'gap_below_floor' => max($gapBelowFloor, 0.0),
            'is_floor_bound' => $isFloorBound,
            'is_ceiling_bound' => $isCeilingBound,
            'individual_cost' => (float) $key->individual_cost,
            'cost_tier' => $this->costTierLabel($key),
            'competitor_count' => count($offers) > 0 ? count($offers) - 1 : 0,
            'listed_months_ago' => $key->listed_at !== null ? Carbon::parse($key->listed_at)->diffInMonths(now()) : null,
            'acquired_months_ago' => $key->acquired_at !== null ? Carbon::parse($key->acquired_at)->diffInMonths(now()) : null,
        ];
    }

    /**
     * @param  int[]  $productIds
     * @return Collection<int, Key> indexada por gamivo_id (product_id)
     */
    private function findGoverningKeys(array $productIds): Collection
    {
        // `trade` traz o canal de compra, lido em costTierLabel.
        return Key::with('trade')
            ->whereIn('gamivo_id', array_map('strval', $productIds))
            ->whereNotNull('listed_at')
            ->whereNull('sold_at')
            ->orderBy('listed_at')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Key $key) => (int) $key->gamivo_id)
            ->map(fn (Collection $group) => $group->first());
    }

    /**
     * A margem inicial da key: faixa de custo, ou a margem fixa da compra direta
     * (MinimumMarginPolicy::BUNDLE_STORE_MARGIN), que não olha o custo.
     */
    private function costTierLabel(Key $key): string
    {
        if ($key->purchaseChannel() === PurchaseChannel::BundleStore) {
            return 'bundle_store ('.round(MinimumMarginPolicy::BUNDLE_STORE_MARGIN * 100).'%)';
        }

        $cost = (float) $key->individual_cost;

        return match (true) {
            $cost > MinimumMarginPolicy::VERY_HIGH_COST_THRESHOLD => 'very_high (>15)',
            $cost > MinimumMarginPolicy::HIGH_COST_THRESHOLD => 'high (10-15)',
            $cost < MinimumMarginPolicy::LOW_COST_THRESHOLD => 'low (<1)',
            default => 'default (1-10)',
        };
    }

    private function writeJson(Collection $rows, string $path): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, json_encode($rows->values(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        $this->info("JSON salvo em {$path}");
    }

    private function printSummary(Collection $rows): void
    {
        $total = $rows->count();
        $floorBound = $rows->where('is_floor_bound', true)->count();

        $this->newLine();
        $this->info("Total de ofertas analisadas: {$total}");
        $this->info('Travadas no min_api (floor-bound): '.$floorBound.' ('.($total > 0 ? round($floorBound / $total * 100, 1) : 0).'%)');
        $this->info('Soma do gap (€) represado pelo piso: '.round($rows->sum('gap_below_floor'), 2));

        $this->newLine();
        $this->table(
            ['Tier de custo', 'Total', 'Floor-bound', '%', 'Gap médio (€)'],
            $rows->groupBy('cost_tier')->map(function (Collection $group, string $tier) {
                $t = $group->count();
                $fb = $group->where('is_floor_bound', true)->count();
                $avgGap = $fb > 0 ? round($group->where('is_floor_bound', true)->avg('gap_below_floor'), 2) : 0;

                return [$tier, $t, $fb, ($t > 0 ? round($fb / $t * 100, 1) : 0).'%', $avgGap];
            })->values()
        );
    }
}
