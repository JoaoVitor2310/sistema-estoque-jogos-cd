<?php

namespace App\UseCases\Marketplaces\Gamivo;

use App\Domain\Enums\OffersUpdateMode;
use App\Domain\Pricing\ComparisonAlgorithm;
use App\Domain\Pricing\ComparisonResult;
use App\Domain\Pricing\MinMaxPriceCalculator;
use App\Domain\Pricing\OfferData;
use App\Services\External\GamivoApiService;
use App\Services\Keys\KeyCalculationService;
use App\Services\Keys\KeyRepository;
use Illuminate\Support\Facades\Log;

/**
 * Reprecifica ofertas ativas na Gamivo com frequência variável por posição:
 *
 *  - WeAreLowest    : somos o 1º mais barato → roda a cada 5 minutos para subir o preço
 *  - WeAreNotLowest : não somos o 1º → roda a cada hora para recuperar posição
 *  - null (padrão)  : processa todos (útil para execução manual via artisan)
 *
 * Migrado de GET /api/update-offers (gamivo-carca-deals, Node.js).
 * Documentação: docs/GAMIVO.md — seção "Fluxo A: update-offers".
 *
 * ⚠️  Chama a API Gamivo em produção. Nunca instanciar fora de contexto autorizado.
 */
class UpdateOffersUseCase
{
    /**
     * Produtos ignorados na reprecificação (hardcoded — replicado do Node.js).
     *  1767  = Random Game on Gamivo
     */
    private const IGNORED_PRODUCT_IDS = [1767];

    public function __construct(
        private readonly GamivoApiService $gamivoApi,
        private readonly KeyCalculationService $keyCalculationService,
        private readonly KeyRepository $keyRepository,
    ) {}

    /**
     * Itera todos os product_ids ativos e reprecifica conforme o algoritmo de comparação.
     * Erros por produto são logados e não interrompem os demais.
     *
     * @param  OffersUpdateMode|null  $mode  Filtra por posição no ranking; null = processa todos
     * @return int[] product_ids cujo preço foi atualizado com sucesso
     */
    public function execute(?OffersUpdateMode $mode = null): array
    {
        $fee = $this->keyCalculationService->getMarketplaceFee();
        $sellerName = config('services.gamivo.seller_name');

        $activeOffers = $this->gamivoApi->getActiveOffers();

        $productIds = array_unique(array_column($activeOffers, 'product_id'));

        $updated = [];
        $updatedDetails = [];
        $errors = [];

        foreach ($productIds as $productId) {
            $productId = (int) $productId;

            if (in_array($productId, self::IGNORED_PRODUCT_IDS, true)) {
                continue;
            }

            try {
                $result = $this->processProduct($productId, $sellerName, $fee, $mode);

                if ($result !== null) {
                    $updated[] = $productId;
                    $updatedDetails[] = $result;
                }
            } catch (\Throwable $e) {
                $errors[$productId] = $e->getMessage();
                Log::error("UpdateOffersUseCase: erro ao processar produto {$productId}: {$e->getMessage()}");
            }
        }

        Log::channel('schedulers')->info('UpdateOffersUseCase', [
            'mode' => $mode?->name ?? 'all',
            'total_products' => count($productIds),
            'updated' => count($updated),
            'errors' => count($errors),
            'error_products' => $errors,
            'updated_details' => $updatedDetails,
        ]);

        return $updated;
    }

    // ── Privados ──────────────────────────────────────────────────────────────

    /**
     * Processa um produto: filtra por modo, compara preços, aplica clamp e envia atualização à Gamivo.
     * Retorna um array com detalhes do update para log, ou null se não houve ação.
     *
     * @return array{game_name: string, old_retail: float, new_retail: float}|null
     */
    private function processProduct(int $productId, string $sellerName, $fee, ?OffersUpdateMode $mode): ?array
    {
        $rawOffers = $this->gamivoApi->getOffersForProduct($productId);

        if (empty($rawOffers)) {
            return null;
        }

        // Offers chegam ordenadas por retail_price ASC (GamivoApiService garante a ordem).
        // O 1º elemento é o mais barato — se for nós, somos o lowest.
        $weAreLowest = ($rawOffers[0]['seller_name'] ?? '') === $sellerName;

        if ($mode === OffersUpdateMode::WeAreLowest && ! $weAreLowest) {
            return null;
        }

        if ($mode === OffersUpdateMode::WeAreNotLowest && $weAreLowest) {
            return null;
        }

        // Captura nosso retail atual para o log (antes de qualquer alteração)
        $oldRetail = $weAreLowest
            ? (float) ($rawOffers[0]['retail_price'] ?? 0.0)
            : collect($rawOffers)->first(fn ($o) => ($o['seller_name'] ?? '') === $sellerName)['retail_price'] ?? 0.0;

        $offers = array_map(fn ($o) => OfferData::fromArray($o), $rawOffers);
        $result = ComparisonAlgorithm::calculate($offers, $sellerName, $fee);

        if (! $result->shouldUpdate) {
            return null;
        }

        $sellerPrice = MinMaxPriceCalculator::clamp($result->sellerPrice, $this->keyRepository->findMinMaxByGamivoId($productId));
        $data = $this->buildUpdatePayload($sellerPrice, $result);

        $this->gamivoApi->updateOffer($result->offerId, $data);

        $keyInfo = $this->keyRepository->findFirstListedByGamivoId($productId);

        return [
            'game_name' => $keyInfo?->game_name ?? 'unknown',
            'old_retail' => $oldRetail,
            'new_retail' => round($result->targetRetail, 2),
        ];
    }

    /**
     * Monta o corpo do PUT /offers/{offerId}.
     * Recalcula os preços tier a partir do sellerPrice já clampado.
     */
    private function buildUpdatePayload(float $sellerPrice, ComparisonResult $result): array
    {
        if ($result->wholesaleMode === 0) {
            return [
                'wholesale_mode' => 0,
                'seller_price' => $sellerPrice,
            ];
        }

        // Recalcular tier com o preço clampado — garante seller_price > tier (requisito da API)
        $tier = round($sellerPrice / ComparisonAlgorithm::WHOLESALE_DIVISOR, 2);

        return [
            'wholesale_mode' => $result->wholesaleMode,
            'seller_price' => $sellerPrice,
            'tier_one_seller_price' => $tier,
            'tier_two_seller_price' => $tier,
        ];
    }
}
