<?php

namespace App\UseCases\Suppliers;

use App\Domain\Pricing\IncomeCalculator;
use App\Domain\Pricing\OfferCalculator;
use App\Services\Keys\KeyCalculationService;

/**
 * Avalia quais jogos de um fornecedor são rentáveis dado o preço atual em EUR.
 *
 * Fluxo:
 *   1. Carrega taxas Gamivo e preço TF2 via KeyCalculationService (infra)
 *   2. Para cada jogo: calcula income líquido (IncomeCalculator) e converte para
 *      keys TF2 com margem DEFAULT_EVALUATION_TIER (OfferCalculator)
 *   3. Descarta jogos onde o valor TF2 calculado seja zero ou negativo
 */
class EvaluateSupplierProfitabilityUseCase
{
    public function __construct(
        private readonly KeyCalculationService $calculationService,
    ) {}

    /**
     * @param  array<int, array{name: string, price_euro: float, popularity: int, region: string}>  $games
     * @return array<int, array{name: string, price_euro: float, popularity: int, region: string, tf2_price: float}>
     */
    public function execute(array $games): array
    {
        $fee = $this->calculationService->getMarketplaceFee();
        $tf2Price = $this->calculationService->getTf2EuroPrice();

        $profitable = [];

        foreach ($games as $game) {
            $netIncome = IncomeCalculator::forGamivo((float) $game['price_euro'], $fee);
            $tf2Offer = OfferCalculator::tf2Offer($netIncome, OfferCalculator::PROFIT_TIERS[0], $tf2Price);

            if ($tf2Offer <= 0) {
                continue;
            }

            $profitable[] = [
                'name' => $game['name'],
                'price_euro' => (float) $game['price_euro'],
                'popularity' => (int) $game['popularity'],
                'region' => $game['region'],
                'tf2_price' => round($tf2Offer, 2),
            ];
        }

        return $profitable;
    }
}
