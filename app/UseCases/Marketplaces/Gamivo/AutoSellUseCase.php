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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Lista keys elegíveis na Gamivo automaticamente.
 *
 * Keys do mesmo gamivo_id compartilham UMA única oferta na Gamivo, então são
 * processadas em grupo (uma oferta, um upload em lote) — não uma por uma. Isso
 * elimina a contenção "Wait for the current action to end" que mutações repetidas
 * na mesma oferta causavam.
 *
 * A decisão de listar é tomada **por key**: cada key entra se o mercado cobre o seu
 * próprio min_api (que já embute a idade — a MinimumMarginPolicy rebaixa o min_api ao
 * FLOOR para keys velhas). Só então, entre as keys que serão listadas, escolhe-se a
 * **governante** — a mais antiga (menor id) — que define o seller_price único da oferta,
 * pois a Gamivo vende FIFO (a primeira enviada vende primeiro). O upload envia as keys
 * aprovadas em ordem de id ASC.
 *
 * Para cada grupo de gamivo_id:
 *  1. Consulta o mercado atual via ComparisonAlgorithm (detectDumpers: false)
 *  2. Filtra, key a key, quais serão listadas (mercado >= min_api da própria key)
 *  3. Governante = mais antiga entre as aprovadas; calcula o seller_price pelo min/max dela
 *  4. Cria/reativa uma oferta na Gamivo
 *  5. Faz upload das keys aprovadas num único uploadKeys (ordem id ASC)
 *  6. Verifica na oferta quais keys apareceram — marca só as confirmadas
 *  7. Marca listed_at; keys individualmente velhas têm o max_api travado no preço praticado
 *
 * Documentação: docs/GAMIVO.md — seção "Auto-sell: agrupamento por `gamivo_id` (venda FIFO)".
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

    /**
     * Tentativas de verificação da key na oferta após o upload assíncrono.
     * Intervalo de 1 segundo entre cada tentativa (máx KEY_UPLOAD_CHECK_ATTEMPTS segundos de espera).
     */
    public const KEY_UPLOAD_CHECK_ATTEMPTS = 5;

    public function __construct(
        private readonly GamivoApiService $gamivoApi,
        private readonly KeyCalculationService $keyCalculationService,
        private readonly KeyRepository $keyRepository,
    ) {}

    /**
     * Agrupa keys elegíveis por gamivo_id e lista cada grupo (uma oferta) na Gamivo.
     * Erros em um grupo são logados e não interrompem os demais.
     *
     * @return int[] IDs das keys listadas com sucesso
     */
    public function execute(): array
    {
        $keys = $this->keyRepository->findEligibleForAutoSell();
        $fee = $this->keyCalculationService->getMarketplaceFee();
        $sellerName = config('services.gamivo.seller_name');

        $listed = [];        // int[] — IDs retornados pelo método
        $listedDetails = []; // detalhes para o log do scheduler
        $skipped = [];
        $errors = [];

        // Keys do mesmo gamivo_id → uma única oferta na Gamivo → um grupo.
        // findEligibleForAutoSell já retorna ordenado por id ASC, então cada grupo
        // preserva a ordem FIFO (governante = primeira key).
        foreach ($keys->groupBy('gamivo_id') as $groupKeys) {
            try {
                $result = $this->processGroup($groupKeys, $sellerName, $fee);

                $listed = array_merge($listed, $result['listed']);
                $listedDetails = array_merge($listedDetails, $result['listedDetails']);
                $skipped = array_merge($skipped, $result['skipped']);
                $errors = array_merge($errors, $result['errors']);
            } catch (\Throwable $e) {
                // Falha antes de qualquer key confirmar (createOffer/uploadKeys) — grupo inteiro
                foreach ($groupKeys as $key) {
                    $errors[] = [
                        'key_id' => $key->id,
                        'key_code' => $key->key_code,
                        'game_name' => $key->game_name,
                        'gamivo_id' => $key->gamivo_id,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        Log::channel('schedulers')->info('AutoSellUseCase', [
            'eligible' => count($keys),
            'listed' => count($listed),
            'skipped_below_min' => count($skipped),
            'errors' => count($errors),
            'listed_details' => $listedDetails,
            'skipped_details' => $skipped,
            'error_details' => $errors,
        ]);

        return $listed;
    }

    // ── Privados ──────────────────────────────────────────────────────────────

    /**
     * Determina o preço de entrada no mercado dado o preço-alvo e os limites da key.
     *
     * O piso min_api já embute a idade da key: MinimumMarginPolicy::minApi rebaixa o
     * min_api ao FLOOR para keys com >= OLD_KEY_MONTHS meses (o RegulateMinApiUseCase
     * persiste isso antes do AutoSell). Por isso não há mais "age override" aqui — o
     * min_api consultado já é a fonte única do piso.
     *
     * Retorna null quando o mercado está abaixo do min_api da key (não listar).
     */
    private function resolveSellerPrice(float $marketPrice, float $minApi, float $maxApi): ?float
    {
        if ($marketPrice === 0.0) {
            return $maxApi; // sem concorrentes → entrar pelo teto
        }

        if ($marketPrice < $minApi) {
            return null; // mercado abaixo do min_api da key — não listar
        }

        return min($marketPrice, $maxApi); // clamp pelo teto
    }

    /**
     * Processa um grupo de keys do mesmo gamivo_id (uma única oferta na Gamivo).
     *
     * A listagem é decidida por key (marketClearsMinApi): entra quem tem o mercado cobrindo o
     * próprio min_api (que já embute a idade da key via MinimumMarginPolicy). A governante
     * — mais antiga (menor id) ENTRE AS APROVADAS — define o seller_price único, pois a
     * Gamivo vende FIFO. As keys aprovadas são enviadas num único uploadKeys, em ordem de id ASC.
     *
     * @param  Collection<int, Key>  $groupKeys  Ordenadas por id ASC (mais antiga primeiro)
     * @return array{listed: int[], listedDetails: array<int, array>, skipped: array<int, array>, errors: array<int, array>}
     */
    private function processGroup(Collection $groupKeys, string $sellerName, MarketplaceFee $fee): array
    {
        $productId = (int) $groupKeys->first()->gamivo_id;

        // Consulta o mercado uma vez para o produto, sem exigir nossa oferta listada
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

        // Decisão de LISTAR é por key: entra quem tem o mercado cobrindo o próprio min_api
        // (a idade já está embutida no min_api pela MinimumMarginPolicy). As demais são
        // puladas individualmente.
        [$toList, $skippedKeys] = $groupKeys->partition(
            fn (Key $key) => $this->marketClearsMinApi($key, $marketPrice)
        );

        $skipped = $skippedKeys->map(fn (Key $key) => [
            'key_id' => $key->id,
            'key_code' => $key->key_code,
            'game_name' => $key->game_name,
            'gamivo_id' => $key->gamivo_id,
            'min_api' => $key->min_api,
        ])->values()->all();

        if ($toList->isEmpty()) {
            return [
                'listed' => [],
                'listedDetails' => [],
                'skipped' => $skipped,
                'errors' => [],
            ];
        }

        // Governante = mais antiga (menor id) ENTRE AS APROVADAS. Como vende primeiro,
        // ela define o seller_price único da oferta. Por ter passado em marketClearsMinApi,
        // resolveSellerPrice nunca retorna null aqui.
        $governingKey = $toList->first();

        $minApi = $governingKey->min_api !== null ? (float) $governingKey->min_api : MinMaxPriceCalculator::FLOOR;
        $maxApi = $governingKey->max_api !== null ? (float) $governingKey->max_api : MinMaxPriceCalculator::CEILING;

        $sellerPrice = $this->resolveSellerPrice($marketPrice, $minApi, $maxApi);

        // Cria ou reativa a oferta na Gamivo (retail, sem wholesale por padrão no auto-sell)
        $offerId = $this->gamivoApi->createOffer([
            'product' => $productId,
            'seller_price' => $sellerPrice,
            'wholesale_mode' => 0,
            'tier_one_seller_price' => 0,
            'tier_two_seller_price' => 0,
            'status' => 1,
            'keys' => $toList->count(),
            'is_preorder' => false,
        ]);

        if ($offerId === null) {
            throw new \RuntimeException("createOffer retornou null para product_id={$productId}");
        }

        // Quando a oferta já existia (inativa), createOffer apenas a reativa via changeOfferStatus
        // sem aplicar o novo seller_price calculado acima — o preço antigo seria mantido.
        // Chamamos updateOffer para garantir que o preço correto seja sempre aplicado,
        // independente de ser criação nova ou reativação.
        $this->gamivoApi->updateOffer($offerId, [
            'wholesale_mode' => 0,
            'seller_price' => $sellerPrice,
            'status' => 1,
        ]);

        // Aguarda o registro da oferta antes de enviar as chaves (race condition documentada)
        if (! app()->environment('testing')) {
            sleep(self::OFFER_CREATION_DELAY_S);
        }

        // Sobe as keys aprovadas num único upload, em ordem de id ASC (governante primeiro)
        $keyCodes = $toList->pluck('key_code')->all();
        $jobId = $this->gamivoApi->uploadKeys($offerId, $keyCodes);

        if ($jobId === null) {
            throw new \RuntimeException("uploadKeys retornou null — offer={$offerId}");
        }

        // Verifica na oferta quais keys apareceram (upload assíncrono). Só marca as confirmadas.
        $confirmed = $this->confirmUploadedKeys($offerId, $toList);

        if ($confirmed->isEmpty()) {
            throw new \RuntimeException(
                "Nenhuma key aprovada apareceu na oferta após upload — offer={$offerId} job={$jobId}"
            );
        }

        // uploadKeys pode zerar o status da oferta durante o processamento — reativa após confirmar
        $this->gamivoApi->changeOfferStatus($offerId, 1);

        $listed = [];
        $listedDetails = [];

        foreach ($confirmed as $key) {
            // Marca listed_at; keys individualmente velhas têm o max_api travado no preço de
            // listagem, impedindo o UpdateOffersUseCase de subir o preço depois. O min_api não
            // é tocado aqui — RegulateMinApiUseCase já o mantém correto diariamente.
            $updates = ['listed_at' => now()->toDateString()];

            if ($this->isOldKey($key)) {
                $updates['max_api'] = $sellerPrice;
            }

            $key->update($updates);

            $listed[] = $key->id;
            $listedDetails[] = [
                'key_id' => $key->id,
                'key_code' => $key->key_code,
                'game_name' => $key->game_name,
                'gamivo_id' => $key->gamivo_id,
            ];
        }

        // Keys aprovadas mas não confirmadas seguem elegíveis na próxima rodada — registra como aviso
        $errors = $toList->whereNotIn('id', $listed)->map(fn (Key $key) => [
            'key_id' => $key->id,
            'key_code' => $key->key_code,
            'game_name' => $key->game_name,
            'gamivo_id' => $key->gamivo_id,
            'error' => "Key não confirmada na oferta após upload em lote — offer={$offerId}",
        ])->values()->all();

        return [
            'listed' => $listed,
            'listedDetails' => $listedDetails,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Verifica se o preço-alvo de mercado cobre o min_api da própria key (que já embute
     * a idade, pois a policy rebaixa o min_api ao FLOOR para keys velhas) — o portão que
     * decide se ela entra na listagem. A governante é escolhida depois, entre as keys que
     * passam aqui.
     */
    private function marketClearsMinApi(Key $key, float $marketPrice): bool
    {
        $minApi = $key->min_api !== null ? (float) $key->min_api : MinMaxPriceCalculator::FLOOR;
        $maxApi = $key->max_api !== null ? (float) $key->max_api : MinMaxPriceCalculator::CEILING;

        return $this->resolveSellerPrice($marketPrice, $minApi, $maxApi) !== null;
    }

    /**
     * Verifica na oferta quais keys do grupo apareceram após o upload assíncrono.
     * Faz polling (isKeyListed) checando, a cada tentativa, apenas as keys ainda pendentes.
     *
     * @param  Collection<int, Key>  $groupKeys
     * @return Collection<int, Key> Keys confirmadas como ativas na oferta
     */
    private function confirmUploadedKeys(int $offerId, Collection $groupKeys): Collection
    {
        $pending = $groupKeys->keyBy('id');
        $confirmed = new Collection;

        for ($attempt = 1; $attempt <= self::KEY_UPLOAD_CHECK_ATTEMPTS; $attempt++) {
            // values() copia a lista, tornando seguro remover de $pending durante a iteração
            foreach ($pending->values() as $key) {
                if ($this->gamivoApi->isKeyListed($offerId, $key->key_code)) {
                    $confirmed->push($key);
                    $pending->forget($key->id);
                }
            }

            if ($pending->isEmpty()) {
                break;
            }

            if ($attempt < self::KEY_UPLOAD_CHECK_ATTEMPTS && ! app()->environment('testing')) {
                sleep(1);
            }
        }

        return $confirmed;
    }

    /**
     * Uma key é "velha" quando foi adquirida há >= OLD_KEY_MONTHS meses.
     *
     * Usado apenas para travar o max_api no preço de listagem dessas keys (o piso min_api
     * já é rebaixado ao FLOOR pela MinimumMarginPolicy, então a idade não é reavaliada aqui
     * para decidir listagem/preço). Sem acquired_at, assume-se recente (now).
     */
    private function isOldKey(Key $key): bool
    {
        $acquiredAt = $key->acquired_at !== null
            ? Carbon::parse($key->acquired_at)
            : Carbon::now();

        return $acquiredAt->lt(Carbon::now()->subMonths(KeyEligibility::OLD_KEY_MONTHS));
    }
}
