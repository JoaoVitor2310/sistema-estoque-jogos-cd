<?php

namespace App\Services\Keys;

use App\Domain\Pricing\MinMaxPriceCalculator;
use App\Domain\Pricing\ProfitCalculator;
use App\Domain\Pricing\ValueObjects\MarketplaceFee;
use App\Models\Recursos;
use App\Models\Taxas;
use Illuminate\Support\Facades\Cache;

/**
 * Serviço de cálculo de preços e lucros de keys.
 *
 * Responsabilidades de infraestrutura:
 *  - Carregar taxas do banco com cache (evita 4 queries por request)
 *  - Calcular o income líquido do Gamivo (depende das taxas = infra)
 *  - Delegar cálculos puros ao Domain (ProfitCalculator, MinMaxPriceCalculator)
 *
 * Todos os métodos retornam floats. Formatação para exibição é
 * responsabilidade da camada de apresentação (Vue / API Resources).
 */
class KeyCalculationService
{
    private const FEES_CACHE_KEY = 'marketplace_fees';
    private const TF2_CACHE_KEY  = 'tf2_euro_price';
    private const CACHE_TTL      = 3600; // 1 hora

    private const GAMIVO_MICRO_THRESHOLD = 0.28;
    private const GAMIVO_MICRO_FIXED_FEE = 0.11;
    private const GAMIVO_TIER_THRESHOLD  = 8.0;

    /**
     * Retorna as taxas do Gamivo encapsuladas num VO, com cache de 1 hora.
     * Invalide com Cache::forget('marketplace_fees') ao atualizar taxas no painel.
     */
    public function getMarketplaceFee(): MarketplaceFee
    {
        return Cache::remember(self::FEES_CACHE_KEY, self::CACHE_TTL, function () {
            $rows = Taxas::whereIn('name', [
                'gamivoPercentualMenor',
                'gamivoFixoMenor',
                'gamivoPercentualMaior',
                'gamivoFixoMaior',
            ])->pluck('preco', 'name');

            return MarketplaceFee::fromArray($rows->all());
        });
    }

    /**
     * Retorna o preço em euros de uma key TF2, com cache de 1 hora.
     */
    public function getTf2EuroPrice(): float
    {
        return Cache::remember(self::TF2_CACHE_KEY, self::CACHE_TTL, function () {
            return (float) Recursos::where('name', 'TF2')->value('preco_euro');
        });
    }

    /**
     * Calcula o income líquido do Gamivo para cada key do lote e acumula o somatório.
     *
     * @param  array<int, array<string, mixed>> $games
     * @return array{games: array<int, array<string, mixed>>, somatorioIncomes: float}
     */
    public function calculateFirstFormulas(array $games): array
    {
        $somatorioIncomes = 0.0;

        foreach ($games as &$game) {
            $income = $this->calculateIncome((float) $game['precoCliente']);

            $game['incomeSimulado'] = $income;
            $somatorioIncomes      += $income;
        }
        unset($game);

        return [
            'games'            => $games,
            'somatorioIncomes' => $somatorioIncomes,
        ];
    }

    /**
     * Calcula os lucros de compra e venda de uma key dentro de um lote.
     *
     * Quando $isEdit = true, o custo individual e os lucros de compra não são
     * recalculados — o valor já está fixado no banco.
     *
     * @param  array<string, mixed> $game
     * @return array<string, mixed>
     */
    public function calculateFormulas(array $game, float $somatorioIncomes, bool $isEdit = false): array
    {
        if (!$isEdit) {
            $individualCost = ProfitCalculator::individualCost(
                qtdTF2:           (float) $game['qtdTF2'],
                tf2EuroPrice:     $this->getTf2EuroPrice(),
                somatorioIncomes: $somatorioIncomes,
                gameIncome:       (float) $game['incomeSimulado'],
            );

            $purchaseProfit = ProfitCalculator::purchaseProfit(
                incomeSimulado: (float) $game['incomeSimulado'],
                individualCost: $individualCost,
            );

            $game['valorPagoIndividual'] = $individualCost;
            $game['lucroRS']             = $purchaseProfit;
            $game['lucroPercentual']     = ProfitCalculator::purchaseProfitPercent($purchaseProfit, $individualCost);
        }

        $individualCost = (float) $game['valorPagoIndividual'];
        $rawVendido     = $game['valorVendido'] ?? null;
        $valorVendido   = ($rawVendido !== null && $rawVendido !== '') ? (float) $rawVendido : null;

        $saleProfit = ProfitCalculator::saleProfit($valorVendido, $individualCost);

        $game['lucroVendaRS']         = $saleProfit;
        $game['lucroVendaPercentual'] = ProfitCalculator::saleProfitPercent($saleProfit, $individualCost);

        return $game;
    }

    /**
     * Calcula min e max para a API do Gamivo e devolve o array do jogo enriquecido.
     *
     * @param  array<string, mixed> $game
     * @return array<string, mixed>
     */
    public function calculateMinMaxApi(array $game): array
    {
        $result = MinMaxPriceCalculator::calculate(
            individualCost: (float) $game['valorPagoIndividual'],
            clientPrice:    (float) $game['precoCliente'],
        );

        $game['minApiGamivo'] = $result['min'];
        $game['maxApiGamivo'] = $result['max'];

        return $game;
    }

    /**
     * Calcula os lucros de venda de uma key já vendida.
     * Usado em updateSoldOffers().
     *
     * @return array{lucroVendaRS: float, lucroVendaPercentual: float}
     */
    public function calculateSaleFormulas(float $salePrice, float $individualCost): array
    {
        $saleProfit = ProfitCalculator::saleProfit($salePrice, $individualCost);

        return [
            'lucroVendaRS'         => $saleProfit,
            'lucroVendaPercentual' => ProfitCalculator::saleProfitPercent($saleProfit, $individualCost),
        ];
    }

    /**
     * Calcula o income líquido do Gamivo após as taxas do marketplace.
     *
     * Tiers:
     *  precoCliente < 0.28 → precoCliente − 0.11 (taxa micro, fixa)
     *  precoCliente < 8    → precoCliente × (1 − %menorFee) − fixoMenor
     *  precoCliente >= 8   → precoCliente × (1 − %maiorFee) − fixoMaior
     *
     * Depende de MarketplaceFee (infra), por isso vive aqui e não no Domain.
     */
    private function calculateIncome(float $clientPrice): float
    {
        if ($clientPrice < self::GAMIVO_MICRO_THRESHOLD) {
            return $clientPrice - self::GAMIVO_MICRO_FIXED_FEE;
        }

        $fee = $this->getMarketplaceFee();

        if ($clientPrice < self::GAMIVO_TIER_THRESHOLD) {
            return $clientPrice * (1 - $fee->percentualMenor) - $fee->fixoMenor;
        }

        return $clientPrice * (1 - $fee->percentualMaior) - $fee->fixoMaior;
    }
}
