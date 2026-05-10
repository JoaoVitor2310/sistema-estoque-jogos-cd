<?php

namespace App\UseCases\Marketplaces\Gamivo;

use App\Domain\Pricing\ComparisonAlgorithm;
use App\Domain\Pricing\ComparisonResult;
use App\Domain\Pricing\MinMaxPriceCalculator;
use App\Domain\Pricing\OfferData;
use App\Services\External\GamivoApiService;
use App\Services\Keys\KeyCalculationService;
use App\Services\Keys\KeyRepository;
use Illuminate\Support\Facades\Log;

/**
 * Reprecifica todas as ofertas ativas na Gamivo — executado a cada hora (minuto 5).
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
     *  42931 = Spotify Premium 1 Month US
     */
    private const IGNORED_PRODUCT_IDS = [1767, 42931];

    public function __construct(
        private readonly GamivoApiService $gamivoApi,
        private readonly KeyCalculationService $keyCalculationService,
        private readonly KeyRepository $keyRepository,
    ) {}

    /**
     * Itera todos os product_ids ativos e reprecifica conforme o algoritmo de comparação.
     * Erros por produto são logados e não interrompem os demais.
     *
     * @return int[] product_ids cujo preço foi atualizado com sucesso
     */
    public function execute(): array
    {
        $fee = $this->keyCalculationService->getMarketplaceFee();
        $sellerName = config('services.gamivo.seller_name');

        $activeOffers = $this->gamivoApi->getActiveOffers();
        $productIds = array_unique(array_column($activeOffers, 'product_id'));

        $updated = [];

        foreach ($productIds as $productId) {
            $productId = (int) $productId;

            if (in_array($productId, self::IGNORED_PRODUCT_IDS, true)) {
                continue;
            }

            try {
                $offerId = $this->processProduct($productId, $sellerName, $fee);

                if ($offerId !== null) {
                    $updated[] = $productId;
                }
            } catch (\Throwable $e) {
                Log::error("UpdateOffersUseCase: erro ao processar produto {$productId}: {$e->getMessage()}");
            }
        }

        Log::info('UpdateOffersUseCase: concluído', [
            'total_products' => count($productIds),
            'updated' => count($updated),
        ]);

        return $updated;
    }

    // ── Privados ──────────────────────────────────────────────────────────────

    /**
     * Processa um produto: compara preços, aplica clamp e envia atualização à Gamivo.
     *
     * @return int|null offerId atualizado ou null se nenhuma ação foi necessária
     */
    private function processProduct(int $productId, string $sellerName, $fee): ?int
    {
        $rawOffers = $this->gamivoApi->getOffersForProduct($productId);

        if (empty($rawOffers)) {
            return null;
        }

        $offers = array_map(fn ($o) => OfferData::fromArray($o), $rawOffers);
        $result = ComparisonAlgorithm::calculate($offers, $sellerName, $fee);

        if (! $result->shouldUpdate) {
            return null;
        }

        $sellerPrice = MinMaxPriceCalculator::clamp($result->sellerPrice, $this->keyRepository->findMinMaxByGamivoId($productId));
        $data = $this->buildUpdatePayload($sellerPrice, $result);

        return $this->gamivoApi->updateOffer($result->offerId, $data);
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
