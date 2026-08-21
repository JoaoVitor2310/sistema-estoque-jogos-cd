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
 * Reprecifica ofertas ativas na Gamivo contra concorrentes via ComparisonAlgorithm.
 *
 * O scheduler roda execute() sem mode a cada minuto — uma única passada decide por
 * produto se sobe o preço (já somos o 1º mais barato) ou desce (não somos), evitando
 * dois processos concorrentes batendo na mesma API. O filtro por OffersUpdateMode
 * (WeAreLowest/WeAreNotLowest) continua disponível para execução manual/pontual.
 *
 * Produtos onde somos o único vendedor utilizável não têm concorrente para ancorar
 * o preço. Antes eles não eram tocados e ficavam parados no preço de entrada do
 * auto-sell (o teto de valorização), pedindo múltiplos do valor real do jogo; hoje
 * são reprecificados pelo mercado pesquisado da governante — ver priceAsSoleSeller.
 *
 * Documentação: docs/GAMIVO.md — seção "Algoritmos de Precificação".
 *
 * ⚠️  Chama a API Gamivo em produção. Nunca instanciar fora de contexto autorizado.
 */
class UpdateOffersUseCase
{
    /**
     * Produtos ignorados na reprecificação (hardcoded).
     *  1767  = Random Game on Gamivo
     */
    private const IGNORED_PRODUCT_IDS = [1767];

    /**
     * Tolerância de comparação entre dois preços em euros (precisão de centavo).
     * Não é regra de negócio — só evita que ruído de float dispare um PUT idêntico.
     */
    private const PRICE_EQUALITY_TOLERANCE = 0.001;

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
     * Dois caminhos de precificação, distinguidos no log pela chave `pricing`:
     * `competitor` (há concorrente para ancorar) e `sole_seller` (não há — ver
     * priceAsSoleSeller). Os valores novos saem em unidades diferentes em cada um,
     * por isso cada caminho usa a sua própria chave.
     *
     * @return array{game_name: string, pricing: string, old_retail: float, new_retail: float}|array{game_name: string, pricing: string, old_retail: float, new_seller_price: float}|null
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
            if ($result->reason !== ComparisonResult::REASON_SOLE_SELLER) {
                return null;
            }

            return $this->priceAsSoleSeller($productId, $result, $oldRetail);
        }

        $governingKey = $this->keyRepository->findGoverningKeyByGamivoId($productId);
        $limits = $governingKey !== null ? [
            'min_api' => (float) $governingKey->min_api,
            'max_api' => (float) $governingKey->max_api,
        ] : null;

        $sellerPrice = MinMaxPriceCalculator::clamp($result->sellerPrice, $limits);
        $data = $this->buildUpdatePayload($sellerPrice, $result);

        $this->gamivoApi->updateOffer($result->offerId, $data);

        return [
            'game_name' => $governingKey?->game_name ?? 'unknown',
            'pricing' => 'competitor',
            'old_retail' => $oldRetail,
            'new_retail' => round($result->targetRetail, 2),
        ];
    }

    /**
     * Reprecifica uma oferta que não tem concorrente utilizável no produto.
     *
     * Sem ninguém para ancorar, o preço deixaria de ser comparado e a oferta ficaria
     * parada no teto de valorização (max_api), pedindo múltiplos do valor real do
     * jogo. Aqui o piso passa a ser o mercado pesquisado da governante, via
     * MinMaxPriceCalculator::soleSellerPrice — que também garante o min_api.
     *
     * Sem governante ou sem market_price não há âncora: não mexe no preço.
     *
     * O alvo é constante enquanto o market_price não mudar, então só envia o PUT
     * quando o preço praticado divergir — do contrário seria uma escrita idêntica
     * por minuto, indefinidamente, contra a API de produção. É a única leitura extra
     * do fluxo: getMyOfferForProduct é quem expõe nosso seller_price (o endpoint
     * público de ofertas do produto só devolve retail_price).
     *
     * @return array{game_name: string, pricing: string, old_retail: float, new_seller_price: float}|null
     */
    private function priceAsSoleSeller(int $productId, ComparisonResult $result, float $oldRetail): ?array
    {
        $governingKey = $this->keyRepository->findGoverningKeyByGamivoId($productId);

        if ($governingKey === null) {
            return null;
        }

        $marketPrice = (float) $governingKey->market_price;

        if ($marketPrice <= 0.0) {
            return null;
        }

        $sellerPrice = MinMaxPriceCalculator::soleSellerPrice(
            $marketPrice,
            (float) $governingKey->min_api,
            (float) $governingKey->max_api,
        );

        $currentOffer = $this->gamivoApi->getMyOfferForProduct($productId);
        $currentSellerPrice = (float) ($currentOffer['seller_price'] ?? 0.0);

        if (abs($currentSellerPrice - $sellerPrice) < self::PRICE_EQUALITY_TOLERANCE) {
            return null;
        }

        $this->gamivoApi->updateOffer($result->offerId, $this->buildUpdatePayload($sellerPrice, $result));

        return [
            'game_name' => $governingKey->game_name ?? 'unknown',
            'pricing' => 'sole_seller',
            'old_retail' => $oldRetail,
            'new_seller_price' => $sellerPrice,
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
