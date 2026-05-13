<?php

/*
|--------------------------------------------------------------------------
| ComparisonAlgorithm — unit tests
|--------------------------------------------------------------------------
|
| Taxas de referência (espelhando produção):
|   percentLow  = 0.060, fixedLow  = 0.250  (preços < €8)
|   percentHigh = 0.080, fixedHigh = 0.400  (preços ≥ €8)
|
| Fórmula sellerPrice = retailPrice × (1 − percent) − fixed
|
| Cenários cobertos:
|   - Sem concorrentes (array vazio, oferta única, nossa oferta ausente)
|   - Já somos o mais barato (sem margem / com margem para subir)
|   - Não somos o mais barato (caso normal / price dumper / checkOthersApi)
|   - Wholesale mode
|   - detectDumpers = false (AutoSell / WhenToSell)
|
*/

use App\Domain\Pricing\ComparisonAlgorithm;
use App\Domain\Pricing\OfferData;
use App\Domain\Pricing\ValueObjects\MarketplaceFee;

// Helper: cria um OfferData com valores default razoáveis
function offer(
    int $id,
    string $sellerName,
    float $retailPrice,
    int $completedOrders = 5000,
    int $wholesaleMode = 0,
): OfferData {
    return new OfferData($id, $sellerName, $retailPrice, $completedOrders, $wholesaleMode);
}

describe('ComparisonAlgorithm', function () {

    beforeEach(function () {
        $this->fee = new MarketplaceFee(
            percentLow: 0.060,
            fixedLow: 0.250,
            percentHigh: 0.080,
            fixedHigh: 0.400,
        );
    });

    // ── Sem concorrentes ──────────────────────────────────────────────────────

    describe('no competitors', function () {

        it('returns noAction when the offers array is empty', function () {
            $result = ComparisonAlgorithm::calculate([], 'CarcaDeals', $this->fee);

            expect($result->shouldUpdate)->toBeFalse()
                ->and($result->reason)->toBe('no_competitors');
        });

        it('returns noAction when we are the only seller', function () {
            // Array com apenas nossa oferta — não há 2º colocado
            $offers = [offer(1, 'CarcaDeals', 3.00)];

            $result = ComparisonAlgorithm::calculate($offers, 'CarcaDeals', $this->fee);

            expect($result->shouldUpdate)->toBeFalse()
                ->and($result->reason)->toBe('no_competitors');
        });

        it('returns noAction when our offer is not present in the list', function () {
            $offers = [
                offer(1, 'CompetitorA', 3.00),
                offer(2, 'CompetitorB', 3.50),
            ];

            $result = ComparisonAlgorithm::calculate($offers, 'CarcaDeals', $this->fee);

            expect($result->shouldUpdate)->toBeFalse()
                ->and($result->reason)->toBe('no_competitors');
        });
    });

    // ── Já somos o mais barato ────────────────────────────────────────────────

    describe('we are the cheapest', function () {

        it('raises the price when the gap to 2nd is exactly at the minimum threshold (€0.04)', function () {
            // diff = 3.04 - 3.00 = 0.04 = MIN_PRICE_DIFF_TO_ACT → deve atualizar (condição é <, não <=)
            // target retail = 3.04 − 0.014 = 3.026
            // sellerPrice   = 3.026 × 0.94 − 0.25 = 2.59444 → 2.59
            $offers = [
                offer(1, 'CarcaDeals', 3.00),
                offer(2, 'CompetitorA', 3.04),
            ];

            $result = ComparisonAlgorithm::calculate($offers, 'CarcaDeals', $this->fee);

            expect($result->shouldUpdate)->toBeTrue()
                ->and($result->sellerPrice)->toEqualWithDelta(2.59, 0.001);
        });

        it('raises the price when the gap to 2nd is at or above the minimum threshold (€0.04)', function () {
            // target retail = 3.50 − 0.014 = 3.486
            // sellerPrice   = 3.486 × 0.94 − 0.25 = 3.02684 → 3.03
            $offers = [
                offer(10, 'CarcaDeals', 3.00),
                offer(20, 'CompetitorA', 3.50),
            ];

            $result = ComparisonAlgorithm::calculate($offers, 'CarcaDeals', $this->fee);

            expect($result->shouldUpdate)->toBeTrue()
                ->and($result->sellerPrice)->toEqualWithDelta(3.03, 0.001)
                ->and($result->offerId)->toBe(10);
        });
    });

    // ── Não somos o mais barato — caso normal ─────────────────────────────────

    describe('we are not the cheapest — normal case', function () {

        it('undercuts the lowest competitor by PRICE_STEP', function () {
            // lowestPrice = 3.00
            // target retail = 3.00 − 0.014 = 2.986
            // sellerPrice   = 2.986 × 0.94 − 0.25 = 2.55684 → 2.56
            $offers = [
                offer(1, 'CompetitorA', 3.00),
                offer(2, 'CompetitorB', 3.20),
                offer(3, 'CarcaDeals', 3.30),
            ];

            $result = ComparisonAlgorithm::calculate($offers, 'CarcaDeals', $this->fee);

            expect($result->shouldUpdate)->toBeTrue()
                ->and($result->sellerPrice)->toEqualWithDelta(2.56, 0.001)
                ->and($result->offerId)->toBe(3);
        });
    });

    // ── Price dumper ──────────────────────────────────────────────────────────

    describe('price dumper detection', function () {

        it('ignores the anomalously cheap seller and aims at the 2nd place price', function () {
            // 2nd price = 2.00, lowest = 1.00; diff = 1.00 >= 10% of 2.00 (0.20) → price dumper
            // target retail = 2.00 − 0.014 = 1.986
            // sellerPrice   = 1.986 × 0.94 − 0.25 = 1.61684 → 1.62
            $offers = [
                offer(1, 'PriceDumper', 1.00),
                offer(2, 'CompetitorB', 2.00),
                offer(3, 'CarcaDeals', 3.30),
            ];

            $result = ComparisonAlgorithm::calculate($offers, 'CarcaDeals', $this->fee);

            expect($result->shouldUpdate)->toBeTrue()
                ->and($result->sellerPrice)->toEqualWithDelta(1.62, 0.001);
        });

        it('returns noAction when we are already in 2nd place behind the price dumper', function () {
            // Já somos o 2º → já estamos na melhor posição possível
            $offers = [
                offer(1, 'PriceDumper', 1.00),
                offer(2, 'CarcaDeals', 2.00),
                offer(3, 'CompetitorC', 3.00),
            ];

            $result = ComparisonAlgorithm::calculate($offers, 'CarcaDeals', $this->fee);

            expect($result->shouldUpdate)->toBeFalse()
                ->and($result->reason)->toBe('already_best');
        });

        it('does not trigger price dumper detection when the price gap is below the threshold', function () {
            // diff = 2.00 - 1.85 = 0.15; threshold = 2.00 * 0.10 = 0.20; 0.15 < 0.20 → não é dumper
            // Segue caminho normal: lowestPrice = 1.85
            // target retail = 1.85 − 0.014 = 1.836
            // sellerPrice   = 1.836 × 0.94 − 0.25 = 1.47584 → 1.48
            $offers = [
                offer(1, 'CompetitorA', 1.85),
                offer(2, 'CompetitorB', 2.00),
                offer(3, 'CarcaDeals', 3.30),
            ];

            $result = ComparisonAlgorithm::calculate($offers, 'CarcaDeals', $this->fee);

            expect($result->shouldUpdate)->toBeTrue()
                ->and($result->sellerPrice)->toEqualWithDelta(1.48, 0.001);
        });

        it('uses the 5% threshold for 2nd prices at or below €1', function () {
            // 2nd price = 0.80 (≤ 1); lowest = 0.75; diff = 0.05; threshold = 0.80 * 0.05 = 0.04; 0.05 >= 0.04 → dumper
            // target retail = 0.80 − 0.014 = 0.786
            // sellerPrice   = 0.786 × 0.94 − 0.25 = 0.73884 − 0.25 = 0.48884 → 0.49
            $offers = [
                offer(1, 'PriceDumper', 0.75),
                offer(2, 'CompetitorB', 0.80),
                offer(3, 'CarcaDeals', 1.20),
            ];

            $result = ComparisonAlgorithm::calculate($offers, 'CarcaDeals', $this->fee);

            expect($result->shouldUpdate)->toBeTrue()
                ->and($result->sellerPrice)->toEqualWithDelta(0.49, 0.001);
        });
    });

    // ── checkOthersApi (anti-bot) ─────────────────────────────────────────────

    describe('checkOthersApi — API competitor detection', function () {

        it('targets the 3rd seller when we are 2nd behind a bot competitor', function () {
            // [0] Buy-n-Play 3.00 (bot), [1] CarcaDeals 3.30 (nós), [2] ThirdComp 4.00
            // (second − first) = 0.30; 10% of second = 0.33; 0.30 ≤ 0.33 ✓
            // (third − second) = 0.70; 10% of third  = 0.40; 0.70 ≥ 0.40 ✓
            // retail alvo = 4.00 − 0.015 = 3.985
            // sellerPrice = 3.985 × 0.94 − 0.25 = 3.4959 → 3.50
            $offers = [
                offer(1, 'Buy-n-Play', 3.00),
                offer(2, 'CarcaDeals', 3.30),
                offer(3, 'ThirdComp', 4.00),
            ];

            $result = ComparisonAlgorithm::calculate($offers, 'CarcaDeals', $this->fee);

            expect($result->shouldUpdate)->toBeTrue()
                ->and($result->sellerPrice)->toEqualWithDelta(3.50, 0.001);
        });

        it('falls through to normal logic when checkOthersApi gap condition is not met', function () {
            // (third − second) = 3.35 − 3.30 = 0.05; 10% of third = 0.335; 0.05 < 0.335 → não dispara
            // Segue caminho normal com sellersToIgnore: Buy-n-Play filtrado
            // filteredCompetitors = [ThirdComp 3.35]
            // lowestPrice = 3.35; target = 3.35 − 0.014 = 3.336
            // sellerPrice = 3.336 × 0.94 − 0.25 = 3.135584 − 0.25 = 2.885584 → 2.89
            $offers = [
                offer(1, 'Buy-n-Play', 3.00),
                offer(2, 'CarcaDeals', 3.30),
                offer(3, 'ThirdComp', 3.35),
            ];

            $result = ComparisonAlgorithm::calculate($offers, 'CarcaDeals', $this->fee);

            expect($result->shouldUpdate)->toBeTrue()
                ->and($result->sellerPrice)->toEqualWithDelta(2.89, 0.001);
        });

        it('does not trigger checkOthersApi when the bot is not in position [0]', function () {
            // Buy-n-Play em 3º, não em 1º → checkOthersApi não disparado
            // CompetitorA não está em SELLERS_TO_IGNORE; Buy-n-Play em 3º é filtrado
            // filteredCompetitors = [CompetitorA 3.00]; target = 2.986; sellerPrice = 2.56
            $offers = [
                offer(1, 'CompetitorA', 3.00),
                offer(2, 'CarcaDeals', 3.30),
                offer(3, 'Buy-n-Play', 4.00),
            ];

            $result = ComparisonAlgorithm::calculate($offers, 'CarcaDeals', $this->fee);

            expect($result->shouldUpdate)->toBeTrue()
                ->and($result->sellerPrice)->toEqualWithDelta(2.56, 0.001);
        });
    });

    // ── Wholesale ─────────────────────────────────────────────────────────────

    describe('wholesale mode', function () {

        it('returns tier prices when our offer has wholesale mode 1', function () {
            // Mesmo cenário normal: sellerPrice = 2.56
            // tier = round(2.56 / 1.035, 2) = round(2.4734..., 2) = 2.47
            $offers = [
                offer(1, 'CompetitorA', 3.00),
                offer(2, 'CompetitorB', 3.20),
                offer(3, 'CarcaDeals', 3.30, wholesaleMode: 1),
            ];

            $result = ComparisonAlgorithm::calculate($offers, 'CarcaDeals', $this->fee);

            expect($result->shouldUpdate)->toBeTrue()
                ->and($result->wholesaleMode)->toBe(1)
                ->and($result->tierOneSellerPrice)->toEqualWithDelta(2.47, 0.001)
                ->and($result->tierTwoSellerPrice)->toEqualWithDelta(2.47, 0.001)
                ->and($result->tierOneSellerPrice)->toBeLessThan($result->sellerPrice);
        });

        it('returns zero tier prices when our offer has wholesale mode 0', function () {
            $offers = [
                offer(1, 'CompetitorA', 3.00),
                offer(2, 'CarcaDeals', 3.30),
            ];

            $result = ComparisonAlgorithm::calculate($offers, 'CarcaDeals', $this->fee);

            expect($result->wholesaleMode)->toBe(0)
                ->and($result->tierOneSellerPrice)->toBe(0.0)
                ->and($result->tierTwoSellerPrice)->toBe(0.0);
        });
    });

    // ── detectDumpers = false (AutoSell / WhenToSell) ─────────────────────────

    describe('detectDumpers = false', function () {

        it('does not filter SELLERS_TO_IGNORE — competes against them directly', function () {
            // Estateium NÃO é filtrado; lowestPrice = 3.00; target = 2.986; sellerPrice = 2.56
            $offers = [
                offer(1, 'Estateium', 3.00),
                offer(2, 'CarcaDeals', 3.30),
            ];

            $result = ComparisonAlgorithm::calculate($offers, 'CarcaDeals', $this->fee, detectDumpers: false);

            expect($result->shouldUpdate)->toBeTrue()
                ->and($result->sellerPrice)->toEqualWithDelta(2.56, 0.001);
        });

        it('does not protect against price dumpers — competes against anomalous prices directly', function () {
            // price dumper NÃO é detectado; lowestPrice = 1.00; target = 0.986
            // sellerPrice = 0.986 × 0.94 − 0.25 = 0.92684 − 0.25 = 0.67684 → 0.68
            $offers = [
                offer(1, 'PriceDumper', 1.00),
                offer(2, 'CompetitorB', 2.00),
                offer(3, 'CarcaDeals', 3.30),
            ];

            $result = ComparisonAlgorithm::calculate($offers, 'CarcaDeals', $this->fee, detectDumpers: false);

            expect($result->shouldUpdate)->toBeTrue()
                ->and($result->sellerPrice)->toEqualWithDelta(0.68, 0.001);
        });
    });

    // ── targetRetail ──────────────────────────────────────────────────────────────

    describe('targetRetail', function () {

        it('is set to second offer retail minus PRICE_STEP when we are the cheapest', function () {
            // handleWeAreLowest: second = 3.50 → targetRetail = 3.50 - 0.014 = 3.486
            $offers = [
                offer(10, 'CarcaDeals', 3.00),
                offer(20, 'CompetitorA', 3.50),
            ];

            $result = ComparisonAlgorithm::calculate($offers, 'CarcaDeals', $this->fee);

            expect($result->targetRetail)->toEqualWithDelta(3.486, 0.001);
        });

        it('is set to lowest competitor retail minus PRICE_STEP when we are not the cheapest', function () {
            // handleWeAreNotLowest: lowestCompetitor = 3.00 → targetRetail = 3.00 - 0.014 = 2.986
            $offers = [
                offer(1, 'CompetitorA', 3.00),
                offer(2, 'CompetitorB', 3.20),
                offer(3, 'CarcaDeals', 3.30),
            ];

            $result = ComparisonAlgorithm::calculate($offers, 'CarcaDeals', $this->fee);

            expect($result->targetRetail)->toEqualWithDelta(2.986, 0.001);
        });

        it('is set to third offer retail minus PRICE_STEP in the checkOthersApi path', function () {
            // checkOthersApi: third = 4.00 → targetRetail = 4.00 - 0.014 = 3.986
            $offers = [
                offer(1, 'Buy-n-Play', 3.00),
                offer(2, 'CarcaDeals', 3.30),
                offer(3, 'ThirdComp', 4.00),
            ];

            $result = ComparisonAlgorithm::calculate($offers, 'CarcaDeals', $this->fee);

            expect($result->targetRetail)->toEqualWithDelta(3.986, 0.001);
        });

        it('is zero when noAction', function () {
            $result = ComparisonAlgorithm::calculate([], 'CarcaDeals', $this->fee);

            expect($result->targetRetail)->toBe(0.0);
        });
    });

    // ── requireOurOffer = false (AutoSell / WhenToSell — key ainda não listada) ─

    describe('requireOurOffer = false', function () {

        it('calculates target retail even when our offer is not in the list', function () {
            // Sem oferta do CarcaDeals; lowestPrice = 3.00; target = 2.986; sellerPrice = 2.56
            $offers = [
                offer(1, 'CompetitorA', 3.00),
                offer(2, 'CompetitorB', 3.20),
            ];

            $result = ComparisonAlgorithm::calculate($offers, 'CarcaDeals', $this->fee, requireOurOffer: false);

            expect($result->shouldUpdate)->toBeTrue()
                ->and($result->sellerPrice)->toEqualWithDelta(2.56, 0.001)
                ->and($result->offerId)->toBe(0)
                ->and($result->wholesaleMode)->toBe(0);
        });

        it('returns no_competitors when the list is empty', function () {
            $result = ComparisonAlgorithm::calculate([], 'CarcaDeals', $this->fee, requireOurOffer: false);

            expect($result->shouldUpdate)->toBeFalse()
                ->and($result->reason)->toBe('no_competitors');
        });

        it('returns no_competitors when all competitors are filtered out', function () {
            // detectDumpers = true (padrão) filtra Buy-n-Play; sem outros concorrentes
            $offers = [
                offer(1, 'Buy-n-Play', 3.00),
            ];

            $result = ComparisonAlgorithm::calculate($offers, 'CarcaDeals', $this->fee, requireOurOffer: false);

            expect($result->shouldUpdate)->toBeFalse()
                ->and($result->reason)->toBe('no_competitors');
        });
    });
});
