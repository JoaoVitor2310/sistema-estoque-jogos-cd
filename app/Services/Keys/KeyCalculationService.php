<?php

namespace App\Services\Keys;

use App\Domain\Enums\PurchaseChannel;
use App\Domain\Enums\TradeCurrency;
use App\Domain\Pricing\IncomeCalculator;
use App\Domain\Pricing\MinMaxPriceCalculator;
use App\Domain\Pricing\ProfitCalculator;
use App\Domain\Pricing\ValueObjects\MarketplaceFee;
use App\Models\Asset;
use App\Models\Fee;
use Carbon\Carbon;
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

    private const TF2_CACHE_KEY = 'tf2_euro_price';

    private const TF2_DOLLAR_CACHE_KEY = 'tf2_dollar_price';

    private const CACHE_TTL = 3600; // 1 hora

    /**
     * Retorna as taxas do Gamivo encapsuladas num VO, com cache de 1 hora.
     * Invalide com Cache::forget('marketplace_fees') ao atualizar taxas no painel.
     */
    public function getMarketplaceFee(): MarketplaceFee
    {
        return Cache::remember(self::FEES_CACHE_KEY, self::CACHE_TTL, function () {
            $rows = Fee::whereIn('name', [
                'gamivo_percent_low',
                'gamivo_fixed_low',
                'gamivo_percent_high',
                'gamivo_fixed_high',
            ])->pluck('preco', 'name');

            return MarketplaceFee::fromArray($rows->all());
        });
    }

    /**
     * Descarta os preços da TF2 em cache. Chamado quando Recursos muda: sem isto, o
     * preço novo só valeria depois da hora do cache.
     */
    public static function forgetTf2Prices(): void
    {
        Cache::forget(self::TF2_CACHE_KEY);
        Cache::forget(self::TF2_DOLLAR_CACHE_KEY);
    }

    /**
     * Retorna o preço em euros de uma key TF2, com cache de 1 hora.
     */
    public function getTf2EuroPrice(): float
    {
        return $this->cachedTf2Price(self::TF2_CACHE_KEY, 'price_euro');
    }

    /**
     * Retorna o preço em dólares de uma key TF2, com cache de 1 hora.
     */
    public function getTf2DollarPrice(): float
    {
        return $this->cachedTf2Price(self::TF2_DOLLAR_CACHE_KEY, 'price_dollar');
    }

    /**
     * Preço da TF2 numa coluna de Recursos, cacheado por uma hora **só quando
     * positivo**. Preço zerado é "ainda não cadastrado": cacheá-lo manteria
     * prospecções em dólar em 503 e o import bloqueado por até uma hora depois
     * de a equipe cadastrar a cotação.
     */
    private function cachedTf2Price(string $cacheKey, string $column): float
    {
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return (float) $cached;
        }

        $price = (float) Asset::where('name', 'TF2')->value($column);

        if ($price > 0) {
            Cache::put($cacheKey, $price, self::CACHE_TTL);
        }

        return $price;
    }

    /**
     * Preço de 1 TF2 key na moeda dada. Em TF2 é 1 por definição; em euro e
     * dólar vem da linha da TF2 em Recursos — 0 quando ainda não foi cadastrado.
     */
    public function getTf2Price(TradeCurrency $currency): float
    {
        return match ($currency) {
            TradeCurrency::Tf2 => TradeCurrency::TF2_UNIT_PRICE,
            TradeCurrency::Eur => $this->getTf2EuroPrice(),
            TradeCurrency::Usd => $this->getTf2DollarPrice(),
        };
    }

    /**
     * Calcula o income líquido do Gamivo para cada key do lote e acumula o somatório.
     *
     * @param  array<int, array<string, mixed>>  $games
     * @return array{games: array<int, array<string, mixed>>, somatorioIncomes: float}
     */
    public function calculateFirstFormulas(array $games): array
    {
        $somatorioIncomes = 0.0;

        foreach ($games as &$game) {
            $income = IncomeCalculator::forGamivo((float) $game['market_price'], $this->getMarketplaceFee());

            $game['simulated_income'] = $income;
            $somatorioIncomes += $income;
        }
        unset($game);

        return [
            'games' => $games,
            'somatorioIncomes' => $somatorioIncomes,
        ];
    }

    /**
     * Calcula os lucros de compra e venda de uma key dentro de um lote.
     *
     * Quando $isEdit = true, o custo individual e os lucros de compra não são
     * recalculados — o valor já está fixado no banco.
     *
     * @param  array<string, mixed>  $game
     * @return array<string, mixed>
     */
    public function calculateFormulas(array $game, float $somatorioIncomes, bool $isEdit = false): array
    {
        if (! $isEdit) {
            $individualCost = ProfitCalculator::individualCost(
                qtdTF2: (float) $game['tf2_quantity'],
                tf2EuroPrice: $this->getTf2EuroPrice(),
                somatorioIncomes: $somatorioIncomes,
                gameIncome: (float) $game['simulated_income'],
            );

            $purchaseProfit = ProfitCalculator::purchaseProfit(
                incomeSimulado: (float) $game['simulated_income'],
                individualCost: $individualCost,
            );

            $game['individual_cost'] = $individualCost;
            $game['purchase_profit'] = $purchaseProfit;
            $game['purchase_profit_percent'] = ProfitCalculator::purchaseProfitPercent($purchaseProfit, $individualCost);
        }

        $individualCost = (float) $game['individual_cost'];
        $rawVendido = $game['sold_price'] ?? null;
        $valorVendido = ($rawVendido !== null && $rawVendido !== '') ? (float) $rawVendido : null;

        $saleProfit = ProfitCalculator::saleProfit($valorVendido, $individualCost);

        $game['sale_profit'] = $saleProfit;
        $game['sale_profit_percent'] = ProfitCalculator::saleProfitPercent($saleProfit, $individualCost);

        return $game;
    }

    /**
     * Calcula min e max para a API do Gamivo e devolve o array do jogo enriquecido.
     *
     * @param  array<string, mixed>  $game
     * @param  PurchaseChannel  $channel  canal de compra da trade de origem — muda a margem inicial do min
     * @return array<string, mixed>
     */
    public function calculateMinMaxApi(array $game, PurchaseChannel $channel): array
    {
        $result = MinMaxPriceCalculator::calculate(
            individualCost: (float) $game['individual_cost'],
            clientPrice: (float) $game['market_price'],
            acquiredAt: Carbon::parse($game['acquired_at']),
            channel: $channel,
        );

        $game['min_api'] = $result['min'];
        $game['max_api'] = $result['max'];

        return $game;
    }

    /**
     * Calcula os lucros de venda de uma key já vendida.
     * Usado em updateSoldOffers().
     *
     * @return array{sale_profit: float, sale_profit_percent: float}
     */
    public function calculateSaleFormulas(float $salePrice, float $individualCost): array
    {
        $saleProfit = ProfitCalculator::saleProfit($salePrice, $individualCost);

        return [
            'sale_profit' => $saleProfit,
            'sale_profit_percent' => ProfitCalculator::saleProfitPercent($saleProfit, $individualCost),
        ];
    }
}
