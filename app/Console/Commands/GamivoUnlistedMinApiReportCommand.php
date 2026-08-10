<?php

namespace App\Console\Commands;

use App\Domain\Keys\KeyEligibility;
use App\Domain\Pricing\ComparisonAlgorithm;
use App\Domain\Pricing\MinimumMarginPolicy;
use App\Domain\Pricing\OfferData;
use App\Domain\Pricing\ValueObjects\MarketplaceFee;
use App\Models\Key;
use App\Services\External\GamivoApiService;
use App\Services\Keys\KeyCalculationService;
use App\Services\Keys\KeyRepository;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Diagnóstico read-only: para cada grupo de keys elegíveis para auto-sell mas ainda
 * NÃO listadas, reproduz a mesma decisão do AutoSellUseCase::processGroup() (mesma
 * chamada ao ComparisonAlgorithm, detectDumpers: false, requireOurOffer: false) para
 * descobrir quais ficariam de fora hoje porque o mercado não cobre o min_api delas —
 * ou seja, quais nunca chegam a ser listadas, não apenas "vendidas mais caro que o ideal".
 *
 * Diferente do gamivo:min-api-floor-report (que olha ofertas JÁ ativas), aqui o min_api
 * de cada key é regido pelo tier de tempo NÃO listado (UNLISTED_AGING_MARGIN /
 * UNLISTED_MODERATE_MARGIN, via acquired_at) quando a idade já venceu o tier de custo.
 *
 * ⚠️  Chama a API Gamivo de produção: 1x GET /products/{id}/offers por produto elegível
 * (só leitura, nenhum PUT/POST — nunca chama createOffer/uploadKeys). Mesma regra de
 * autorização de qualquer chamada real à Gamivo (ver CLAUDE.md).
 */
class GamivoUnlistedMinApiReportCommand extends Command
{
    protected $signature = 'gamivo:unlisted-min-api-report
        {--json=storage/app/diagnostics/unlisted-min-api-report.json}
        {--limit= : Processa só os N primeiros produtos (para um teste rápido antes do run completo)}
        {--delay-ms=150 : Pausa entre chamadas GET /products/{id}/offers, para não martelar a API}';

    protected $description = 'Simula a decisão de listagem do AutoSellUseCase para keys ainda não listadas, para achar quais nunca entram por causa do min_api (read-only)';

    public function __construct(
        private readonly GamivoApiService $gamivoApi,
        private readonly KeyCalculationService $keyCalculationService,
        private readonly KeyRepository $keyRepository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $fee = $this->keyCalculationService->getMarketplaceFee();
        $sellerName = config('services.gamivo.seller_name');

        $keys = $this->keyRepository->findEligibleForAutoSell();
        $this->info($keys->count().' keys elegíveis para auto-sell (ainda não listadas).');

        $groups = $keys->groupBy('gamivo_id');
        $productIds = $groups->keys()->values();

        if ($limit = $this->option('limit')) {
            $productIds = $productIds->take((int) $limit);
        }

        $delayMs = (int) $this->option('delay-ms');
        $rows = collect();
        $errors = 0;

        $bar = $this->output->createProgressBar($productIds->count());
        $bar->start();

        foreach ($productIds as $productId) {
            $bar->advance();

            try {
                $groupRows = $this->analyzeGroup((int) $productId, $groups->get($productId), $sellerName, $fee);
                $rows = $rows->concat($groupRows);
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

        if ($errors > 0) {
            $this->warn("{$errors} produto(s) falharam ao consultar concorrentes e foram pulados.");
        }

        $jsonPath = $this->option('json');
        $this->writeJson($rows, $jsonPath);
        $this->printSummary($rows);

        return self::SUCCESS;
    }

    /**
     * Reproduz AutoSellUseCase::processGroup(): uma consulta de mercado por produto,
     * decisão de listar avaliada por key (mercado cobre o min_api individual).
     *
     * @param  Collection<int, Key>  $groupKeys
     * @return array<int, array>
     */
    private function analyzeGroup(int $productId, Collection $groupKeys, string $sellerName, MarketplaceFee $fee): array
    {
        $rawOffers = $this->gamivoApi->getOffersForProduct($productId);
        $offers = array_map(fn ($o) => OfferData::fromArray($o), $rawOffers);
        $result = ComparisonAlgorithm::calculate(
            $offers,
            $sellerName,
            $fee,
            detectDumpers: false,
            requireOurOffer: false,
        );
        $marketPrice = $result->sellerPrice;
        $noCompetitors = $marketPrice === 0.0;

        return $groupKeys->map(function (Key $key) use ($productId, $marketPrice, $noCompetitors, $offers) {
            $minApi = (float) $key->min_api;
            $maxApi = (float) $key->max_api;
            $blocked = ! $noCompetitors && $marketPrice < $minApi;

            return [
                'key_id' => $key->id,
                'gamivo_id' => $productId,
                'game_name' => $key->game_name,
                'no_competitors' => $noCompetitors,
                'market_price' => round($marketPrice, 2),
                'min_api' => $minApi,
                'max_api' => $maxApi,
                'is_blocked' => $blocked,
                'gap_below_floor' => $blocked ? round($minApi - $marketPrice, 2) : 0.0,
                'would_list_at' => $noCompetitors ? $maxApi : ($blocked ? null : round(min($marketPrice, $maxApi), 2)),
                'individual_cost' => (float) $key->individual_cost,
                'margin_bucket' => $this->marginBucket($key),
                'competitor_count' => count($offers),
                'acquired_months_ago' => $key->acquired_at !== null ? round(Carbon::parse($key->acquired_at)->diffInMonths(now(), true), 1) : null,
            ];
        })->all();
    }

    /**
     * Classifica qual branch de MinimumMarginPolicy::requiredMargin() (ramo "não listada")
     * está de fato governando o min_api desta key — idade vence custo quando aplicável.
     */
    private function marginBucket(Key $key): string
    {
        if ($key->acquired_at === null) {
            return 'cost tier';
        }

        $acquiredAt = Carbon::parse($key->acquired_at);
        $now = Carbon::now();

        if ($acquiredAt->lt($now->copy()->subMonths(KeyEligibility::OLD_KEY_MONTHS))) {
            return 'FLOOR (>=8m, old stock)';
        }

        if ($acquiredAt->lt($now->copy()->subMonths(MinimumMarginPolicy::UNLISTED_AGING_MONTHS))) {
            return 'age >=6m unlisted (15%)';
        }

        if ($acquiredAt->lt($now->copy()->subMonths(MinimumMarginPolicy::UNLISTED_MODERATE_MONTHS))) {
            return 'age >=4m unlisted (40%)';
        }

        return 'cost tier';
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
        $blocked = $rows->where('is_blocked', true)->count();

        $this->newLine();
        $this->info("Total de keys não listadas analisadas: {$total}");
        $this->info('Bloqueadas pelo min_api (nunca listam hoje): '.$blocked.' ('.($total > 0 ? round($blocked / $total * 100, 1) : 0).'%)');
        $this->info('Custo total parado nessas keys bloqueadas: €'.round($rows->where('is_blocked', true)->sum('individual_cost'), 2));

        $this->newLine();
        $this->table(
            ['Margin bucket', 'Total', 'Bloqueadas', '%', 'Gap médio (€)'],
            $rows->groupBy('margin_bucket')->map(function (Collection $group, string $bucket) {
                $t = $group->count();
                $b = $group->where('is_blocked', true)->count();
                $avgGap = $b > 0 ? round($group->where('is_blocked', true)->avg('gap_below_floor'), 2) : 0;

                return [$bucket, $t, $b, ($t > 0 ? round($b / $t * 100, 1) : 0).'%', $avgGap];
            })->values()
        );
    }
}
