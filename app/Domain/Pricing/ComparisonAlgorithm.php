<?php

namespace App\Domain\Pricing;

use App\Domain\Pricing\ValueObjects\MarketplaceFee;

/**
 * Algoritmo de comparação de preços contra concorrentes na Gamivo.
 *
 * Migrado de comparisonService.ts (gamivo-carca-deals, Node.js).
 *
 * Documentação completa: docs/GAMIVO.md — seção "Algoritmos de Precificação".
 */
final class ComparisonAlgorithm
{
    /** Passo subtraído do menor preço concorrente para garantir que somos mais baratos. */
    public const PRICE_STEP = 0.014;

    /**
     * Ratio para detectar price dumper quando 2º preço > €1.
     * Se diferença >= 10% do 2º preço, o 1º é considerado price dumper.
     */
    public const DUMPER_HIGH_PRICE_RATIO = 0.10;

    /**
     * Ratio para detectar price dumper quando 2º preço ≤ €1.
     * Se diferença >= 5% do 2º preço, o 1º é considerado price dumper.
     */
    public const DUMPER_LOW_PRICE_RATIO = 0.05;

    /** Divisor das taxas wholesale Gamivo (3,5%). */
    public const WHOLESALE_DIVISOR = 1.035;

    /**
     * Vendedores com bot de precificação automática.
     * Quando somos o 2º atrás deles, usar o 3º como referência (checkOthersApi).
     */
    public const API_COMPETITOR_SELLERS = ['Buy-n-Play', 'Playtime'];

    /**
     * Vendedores ignorados na comparação quando $detectDumpers = true.
     * Preços irreais ou manipulados — não compensa competir diretamente.
     */
    public const SELLERS_TO_IGNORE = ['Buy-n-Play', 'Playtime', 'Estateium'];

    /**
     * Calcula o melhor preço competitivo para nossa oferta.
     *
     * Equivalente a compareById() / searchBestPrice() do Node.js.
     *
     * @param  OfferData[]  $offers  Todas as ofertas do produto, ordenadas por retail_price ASC.
     *                               GamivoApiService::getOffersForProduct() já retorna nessa ordem.
     * @param  string  $sellerName  Nome do nosso vendedor na Gamivo (config services.gamivo.seller_name)
     * @param  MarketplaceFee  $fee  Taxas vigentes do marketplace
     * @param  bool  $detectDumpers  true = ativa proteção contra price dumpers + filtra SELLERS_TO_IGNORE.
     *                               false = usado em AutoSell / WhenToSell (sem filtro nem proteção).
     * @param  bool  $requireOurOffer  true = retorna no_competitors se não tivermos oferta listada (padrão).
     *                                 false = calcula o target retail mesmo sem oferta nossa na lista
     *                                 (usado em AutoSell / WhenToSell para consultar o preço de mercado).
     */
    public static function calculate(
        array $offers,
        string $sellerName,
        MarketplaceFee $fee,
        bool $detectDumpers = true,
        bool $requireOurOffer = true,
    ): ComparisonResult {
        if (empty($offers)) {
            return ComparisonResult::noAction('no_competitors');
        }

        // Localizar nossa oferta para obter offerId e wholesaleMode (pode ser null se não listada)
        $ourOffer = null;
        foreach ($offers as $offer) {
            if ($offer->sellerName === $sellerName) {
                $ourOffer = $offer;
                break;
            }
        }

        if ($requireOurOffer && $ourOffer === null) {
            return ComparisonResult::noAction('no_competitors');
        }

        $isLowest = $offers[0]->sellerName === $sellerName;

        return $isLowest
            ? self::handleWeAreLowest($offers, $ourOffer, $fee)
            : self::handleWeAreNotLowest($offers, $ourOffer, $fee, $sellerName, $detectDumpers);
    }

    // ── Casos principais ──────────────────────────────────────────────────────

    /**
     * Já somos o mais barato — subir preço para logo abaixo do 2º colocado.
     *
     * @param  OfferData[]  $offers
     */
    private static function handleWeAreLowest(
        array $offers,
        OfferData $ourOffer,
        MarketplaceFee $fee,
    ): ComparisonResult {
        if (count($offers) < 2) {
            return ComparisonResult::noAction('no_competitors');
        }

        $targetRetail = $offers[1]->retailPrice - self::PRICE_STEP;

        return self::buildResult(round(IncomeCalculator::forGamivo($targetRetail, $fee), 2), $ourOffer);
    }

    /**
     * Não somos o mais barato — calcular novo preço competitivo.
     *
     * Ordem de verificação:
     *  1. checkOthersApi — concorrente com bot (anti-guerra de preços)
     *  2. Filtrar SELLERS_TO_IGNORE (se detectDumpers)
     *  3. Detectar price dumper (se detectDumpers)
     *  4. Calcular preço final = lowestPrice - PRICE_STEP
     *
     * @param  OfferData[]  $offers
     */
    private static function handleWeAreNotLowest(
        array $offers,
        ?OfferData $ourOffer,
        MarketplaceFee $fee,
        string $sellerName,
        bool $detectDumpers,
    ): ComparisonResult {
        // 1. Anti-API-competitor: somos 2º atrás de um bot → mirar no 3º
        if (count($offers) >= 3
            && in_array($offers[0]->sellerName, self::API_COMPETITOR_SELLERS, true)
            && $offers[1]->sellerName === $sellerName
        ) {
            $checkResult = self::checkOthersApi($offers);
            if ($checkResult !== null) {
                return self::buildResult(round(IncomeCalculator::forGamivo($checkResult, $fee), 2), $ourOffer);
            }
        }

        // 2. Preço do 2º no ranking original — referência para detecção de price dumper
        $secondPrice = isset($offers[1]) ? $offers[1]->retailPrice : null;

        // 3a. Excluir nossa própria oferta da lista de concorrentes
        $competitors = array_values(array_filter(
            $offers,
            fn (OfferData $o) => $o->sellerName !== $sellerName,
        ));

        // 3b. Se detectDumpers, excluir sellers com preços irreais (bots, manipuladores)
        if ($detectDumpers) {
            $competitors = array_values(array_filter(
                $competitors,
                fn (OfferData $o) => ! in_array($o->sellerName, self::SELLERS_TO_IGNORE, true),
            ));
        }

        if (empty($competitors)) {
            return ComparisonResult::noAction('no_competitors');
        }

        // Array já vem ordenado ASC — [0] é o menor preço entre os concorrentes filtrados
        $lowestPrice = $competitors[0]->retailPrice;

        // 4. Detecção de price dumper: concorrente com preço anomalamente baixo vs. o 2º
        if ($detectDumpers && $secondPrice !== null) {
            $diff = $secondPrice - $lowestPrice;
            $threshold = $secondPrice > 1.0
                ? $secondPrice * self::DUMPER_HIGH_PRICE_RATIO
                : $secondPrice * self::DUMPER_LOW_PRICE_RATIO;

            if ($diff >= $threshold) {
                // Já somos o 2º no ranking original → já estamos na melhor posição possível
                if ($offers[1]->sellerName === $sellerName) {
                    return ComparisonResult::noAction('already_best');
                }

                // Ignorar price dumper e mirar no 2º colocado do ranking original
                $lowestPrice = $secondPrice;
            }
        }

        $targetRetail = $lowestPrice - self::PRICE_STEP;

        return self::buildResult(round(IncomeCalculator::forGamivo($targetRetail, $fee), 2), $ourOffer);
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    /**
     * Detecta guerra de preços com bot concorrente e sugere mira no 3º colocado.
     *
     * Condição: somos 2º, [0] é bot; a diferença entre nós e o bot é pequena,
     * mas o 3º colocado está bem acima de nós → subir para logo abaixo do 3º.
     *
     * Retorna o retail_price alvo (com taxa) ou null se a condição não se aplica.
     *
     * @param  OfferData[]  $offers
     */
    private static function checkOthersApi(array $offers): ?float
    {
        $first = $offers[0]->retailPrice;   // bot concorrente
        $second = $offers[1]->retailPrice;  // nós
        $third = $offers[2]->retailPrice;   // alvo

        // Diferença entre nós e o bot deve ser pequena (≤ 10% do nosso preço)
        if (($second - $first) > $second * 0.1) {
            return null;
        }

        // 3º deve estar bem acima de nós (≥ 10% do 3º preço)
        if (($third - $second) < $third * 0.1) {
            return null;
        }

        // Retorna retail price alvo; o chamador NÃO aplica PRICE_STEP adicionalmente
        return $third - self::PRICE_STEP;
    }

    /**
     * Monta o ComparisonResult com preços wholesale calculados se necessário.
     * Quando $ourOffer é null (requireOurOffer = false), offerId = 0 e wholesaleMode = 0.
     */
    private static function buildResult(float $sellerPrice, ?OfferData $ourOffer): ComparisonResult
    {
        if ($ourOffer === null || $ourOffer->wholesaleMode === 0) {
            return ComparisonResult::updatePrice($sellerPrice, $ourOffer !== null ? $ourOffer->id : 0, 0);
        }

        // tier = sellerPrice / 1.035 (taxa wholesale de 3,5%)
        // Deve ser < sellerPrice (requisito da API Gamivo)
        $tier = round($sellerPrice / self::WHOLESALE_DIVISOR, 2);

        return ComparisonResult::updatePrice(
            $sellerPrice,
            $ourOffer->id,
            $ourOffer->wholesaleMode,
            $tier,
            $tier,
        );
    }
}
