<?php

namespace App\Services;

use App\Domain\Keys\KeyEligibility;
use App\Domain\Keys\KeyPriceAging;
use App\Domain\Pricing\ComparisonAlgorithm;
use App\Domain\Pricing\MinMaxPriceCalculator;
use App\Domain\Pricing\OfferData;
use App\Models\Key;
use App\Services\External\GamivoApiService;
use App\Services\Keys\KeyCalculationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class KeyService
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        private readonly GamivoApiService $gamivoApi,
        private readonly KeyCalculationService $keyCalculationService,
    ) {}

    /**
     * Envia alerta por e-mail quando há keys expirando nos próximos 30 dias.
     */
    public function checkExpiringKeys(): void
    {
        $keysAboutToExpire = Key::where('expires_at', '<=', now()->addDays(KeyEligibility::EXPIRY_ALERT_DAYS))
            ->where('expires_at', '>', now())
            ->whereNull('sold_at')
            ->get();

        if ($keysAboutToExpire->isEmpty()) {
            return;
        }

        try {
            Mail::send('emails.expiration-alert', ['jogos' => $keysAboutToExpire], function ($message) {
                $message->to('carcadeals@gmail.com')
                    ->subject('⚠️ Alerta: '.Carbon::now()->format('d/m/Y').' - Jogos expirando em até 30 dias');
            });

            Log::info('Email de expiração enviado com sucesso. Jogos encontrados: '.$keysAboutToExpire->count());
        } catch (\Exception $e) {
            Log::error('Erro ao enviar email de expiração: '.$e->getMessage());
        }
    }

    /**
     * Reduz o min_api ao piso para keys listadas que vencem nos próximos 30 dias.
     * Maximiza a chance de venda antes da expiração.
     */
    public function reduceExpiringListedKeysPrice(): void
    {
        Key::whereNotNull('listed_at')
            ->whereNull('sold_at')
            ->whereBetween('expires_at', [now(), now()->addDays(KeyEligibility::EXPIRY_PRICE_FLOOR_DAYS)])
            ->update(['min_api' => MinMaxPriceCalculator::FLOOR]);
    }

    /**
     * Ajusta o preço mínimo de keys em limbo (listadas há 12+ meses sem venda).
     * Consulta o preço atual no mercado Gamivo e delega o cálculo ao Domain.
     */
    public function checkLimboKeys(): void
    {
        $games = Key::select([
            'id',
            'key_code',
            'gamivo_id',
            'individual_cost',
            'min_api',
            'max_api',
            'listed_at',
        ])
            ->whereNull('sold_at')
            ->whereNotNull('gamivo_id')
            ->where('listed_at', '<=', now()->subMonths(KeyPriceAging::LIMBO_MONTHS_THRESHOLD))
            ->get();

        foreach ($games as $game) {
            $actualPrice = $this->getActualPrice($game->gamivo_id);

            if (! $actualPrice['success']) {
                continue;
            }

            $game->min_api = KeyPriceAging::calculateLimboPrice(
                individualCost: (float) $game->individual_cost,
                actualMarketPrice: (float) $actualPrice['price'],
            );

            $game->save();
        }
    }

    /**
     * Retorna o preço de mercado de referência para o produto na Gamivo.
     *
     * Usa o ComparisonAlgorithm para obter um preço realista:
     *  - Filtra price dumpers (detectDumpers = true)
     *  - Não exige nossa oferta listada (requireOurOffer = false): keys em limbo
     *    podem ter oferta desativada, mas ainda precisamos do preço de mercado
     *    para ajustar min_api corretamente
     *  - Retorna targetRetail (preço de varejo de referência calculado pelo algoritmo)
     */
    private function getActualPrice(string $gamivo_id): array
    {
        try {
            $rawOffers = $this->gamivoApi->getOffersForProduct((int) $gamivo_id);

            if (empty($rawOffers)) {
                return ['success' => false, 'price' => null];
            }

            $offers = array_map(fn ($o) => OfferData::fromArray($o), $rawOffers);
            $fee = $this->keyCalculationService->getMarketplaceFee();
            $sellerName = config('services.gamivo.seller_name');

            $result = ComparisonAlgorithm::calculate(
                $offers,
                $sellerName,
                $fee,
                detectDumpers: true,
                requireOurOffer: false,
            );

            if (! $result->shouldUpdate || $result->targetRetail <= 0) {
                return ['success' => false, 'price' => null];
            }

            return ['success' => true, 'price' => $result->targetRetail];
        } catch (\Throwable $e) {
            Log::error('Error getting actual price of game on Gamivo: '.$e->getMessage().' - '.$e->getLine().' - '.$e->getFile());

            return ['success' => false, 'price' => null];
        }
    }
}
