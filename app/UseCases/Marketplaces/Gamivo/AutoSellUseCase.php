<?php

namespace App\UseCases\Marketplaces\Gamivo;

use App\Domain\Keys\KeyEligibility;
use App\Domain\Pricing\ComparisonAlgorithm;
use App\Domain\Pricing\MinMaxPriceCalculator;
use App\Domain\Pricing\OfferData;
use App\Domain\Pricing\ValueObjects\MarketplaceFee;
use App\Models\Key;
use App\Services\External\GamivoApiService;
use App\Services\Keys\KeyCalculationService;
use App\Services\Keys\KeyRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Lista keys elegíveis na Gamivo automaticamente.
 *
 * Para cada key elegível:
 *  1. Consulta o mercado atual via ComparisonAlgorithm (detectDumpers: false)
 *  2. Calcula o preço alvo e clamba entre min_api e max_api da key
 *  3. Keys com >= OLD_KEY_MONTHS meses ignoram o piso min_api (age override)
 *     e têm o min_api atualizado para o preço de listagem após o upload
 *  4. Cria/reativa oferta na Gamivo
 *  5. Faz upload da chave com retry
 *  6. Aguarda confirmação do job assíncrono
 *  7. Marca listed_at no banco
 *
 * Migrado de GET /api/auto-sell (gamivo-carca-deals, Node.js).
 * Documentação: docs/GAMIVO.md — seção "Fluxo B: auto-sell".
 *
 * ⚠️  Chama a API Gamivo em produção. Nunca instanciar fora de contexto autorizado.
 */
class AutoSellUseCase
{
    /**
     * Delay entre createOffer e uploadKeys (segundos).
     * A Gamivo precisa de tempo para registrar a oferta antes de aceitar keys.
     */
    public const OFFER_CREATION_DELAY_S = 1;

    public function __construct(
        private readonly GamivoApiService $gamivoApi,
        private readonly KeyCalculationService $keyCalculationService,
        private readonly KeyRepository $keyRepository,
    ) {}

    /**
     * Itera keys elegíveis e lista cada uma na Gamivo.
     * Erros por key são logados e não interrompem as demais.
     *
     * @return int[] IDs das keys listadas com sucesso
     */
    public function execute(): array
    {
        $keys = $this->keyRepository->findEligibleForAutoSell();
        $fee = $this->keyCalculationService->getMarketplaceFee();
        $sellerName = config('services.gamivo.seller_name');
        $listed = [];

        foreach ($keys as $key) {
            try {
                if ($this->processKey($key, $sellerName, $fee)) {
                    $listed[] = $key->id;
                }
            } catch (\Throwable $e) {
                Log::error("AutoSellUseCase: erro ao listar key id={$key->id} gamivo_id={$key->gamivo_id}: {$e->getMessage()}");
            }
        }

        Log::info('AutoSellUseCase: concluído', [
            'eligible' => count($keys),
            'listed' => count($listed),
        ]);

        return $listed;
    }

    // ── Privados ──────────────────────────────────────────────────────────────

    /**
     * Determina o preço de entrada no mercado dado o preço do concorrente e os limites da key.
     *
     * Quando $ignoreMinApi é true (keys com >= OLD_KEY_MONTHS meses), o piso min_api é ignorado
     * e a key é listada mesmo que o mercado esteja abaixo do mínimo original.
     * Retorna null apenas quando $ignoreMinApi é false e o mercado está abaixo do mínimo.
     */
    private function resolveSellerPrice(
        float $competitorPrice,
        float $minApi,
        float $maxApi,
        bool $ignoreMinApi = false,
    ): ?float {
        if ($competitorPrice === 0.0) {
            return $maxApi; // sem concorrentes → entrar pelo teto
        }

        if (! $ignoreMinApi && $competitorPrice < $minApi) {
            return null; // mercado abaixo do mínimo — não listar
        }

        return min($competitorPrice, $maxApi); // clamp pelo teto
    }

    /**
     * Processa uma key: calcula preço alvo, cria oferta, faz upload e marca listed_at.
     * Keys com >= OLD_KEY_MONTHS meses ignoram o piso min_api e têm min_api atualizado.
     * Retorna false se o mercado estiver abaixo do mínimo (apenas para keys jovens).
     */
    private function processKey(Key $key, string $sellerName, MarketplaceFee $fee): bool
    {
        $productId = (int) $key->gamivo_id;
        $acquiredAt = $key->acquired_at !== null
            ? Carbon::parse($key->acquired_at)
            : Carbon::now();

        // Keys com >= OLD_KEY_MONTHS meses ignoram o piso min_api (age override)
        $isOldKey = $acquiredAt->lt(Carbon::now()->subMonths(KeyEligibility::OLD_KEY_MONTHS));

        // Consulta o mercado e determina o preço alvo sem exigir nossa oferta listada
        $rawOffers = $this->gamivoApi->getOffersForProduct($productId);
        $offers = array_map(fn ($o) => OfferData::fromArray($o), $rawOffers);
        $result = ComparisonAlgorithm::calculate(
            $offers,
            $sellerName,
            $fee,
            detectDumpers: false,
            requireOurOffer: false,
        );

        $minApi = $key->min_api !== null ? (float) $key->min_api : MinMaxPriceCalculator::FLOOR;
        $maxApi = $key->max_api !== null ? (float) $key->max_api : MinMaxPriceCalculator::CEILING;

        $sellerPrice = $this->resolveSellerPrice($result->sellerPrice, $minApi, $maxApi, ignoreMinApi: $isOldKey);

        if ($sellerPrice === null) {
            Log::info("AutoSellUseCase: mercado abaixo do min_api para gamivo_id={$key->gamivo_id}, pulando", [
                'sellerPrice' => $result->sellerPrice,
                'min_api' => $minApi,
            ]);

            return false;
        }

        // Cria ou reativa oferta na Gamivo (retail, sem wholesale por padrão no auto-sell)
        $offerId = $this->gamivoApi->createOffer([
            'product_id' => $productId,
            'seller_price' => $sellerPrice,
            'wholesale_mode' => 0,
        ]);

        if ($offerId === null) {
            throw new \RuntimeException("createOffer retornou null para product_id={$productId}");
        }

        // Aguarda o registro da oferta antes de enviar a chave (race condition documentada — Gotcha #6)
        if (! app()->environment('testing')) {
            sleep(self::OFFER_CREATION_DELAY_S);
        }

        // Inicia o upload assíncrono da key
        $jobId = $this->gamivoApi->uploadKeys($offerId, [$key->key_code]);

        if ($jobId === null) {
            throw new \RuntimeException("uploadKeys retornou null para offer={$offerId}");
        }

        // Aguarda conclusão do job assíncrono
        $this->gamivoApi->waitForUpload($offerId, $jobId);

        // Confirma que a key está de fato ativa na oferta antes de marcar listed_at
        if (! $this->gamivoApi->isKeyListed($offerId, $key->key_code)) {
            throw new \RuntimeException("Key não aparece como ativa após upload para offer={$offerId}");
        }

        // Marca listed_at; keys velhas têm min/max travados no preço de listagem:
        //  - max_api = sellerPrice → impede o UpdateOffersUseCase de subir o preço depois
        //  - min_api = FLOOR      → permite máxima flexibilidade de baixar para competir
        $updates = ['listed_at' => now()->toDateString()];
        if ($isOldKey) {
            $updates['min_api'] = MinMaxPriceCalculator::FLOOR;
            $updates['max_api'] = $sellerPrice;
        }
        $key->update($updates);

        return true;
    }
}
