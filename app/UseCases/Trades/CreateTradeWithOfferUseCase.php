<?php

namespace App\UseCases\Trades;

use App\Domain\Pricing\CurrencyPriceUnavailable;
use App\Domain\Trades\DeliveryCredential;
use App\Domain\Trades\RequestReusedForAnotherSupplier;
use App\Domain\Trades\TradeLineBuilder;
use App\Models\Trade;
use App\Services\Bundles\BundleService;
use App\Services\Keys\KeyCalculationService;
use App\Services\Suppliers\SupplierService;
use App\Services\Trades\TradeLineFlagsService;
use App\Services\Trades\TradeOfferService;
use App\UseCases\Trades\DTO\TradeWithOfferDTO;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cria a trade de uma lista precificada já com a primeira oferta ao supplier.
 *
 * Tudo numa transação só: supplier, trade, linhas, credencial de entrega e
 * oferta. Quando não há o que ofertar — supplier bloqueado, ou nenhum jogo
 * ofertável —, a transação é desfeita e nada fica gravado: a resposta "nenhum
 * jogo nos interessa" não deixa trade vazia para trás.
 *
 * A trade registra a lista inteira, ofertada ou não: o jogo encalhado ou em
 * giveaway vira linha marcada, fora da oferta (docs/adr/0013 e 0014).
 *
 * **Idempotente pelo `request_uuid`.** O mesmo pedido repetido (timeout do
 * lado de lá, nova tentativa do job) devolve a trade que ele já criou, sem
 * gravar nada — senão o supplier veria duas trades com duas ofertas. Pedido
 * sem `request_uuid` cria sempre, como antes. O id só vale para o mesmo
 * supplier: com outro, a chamada é recusada, nunca respondida com a trade
 * alheia. O resto do corpo não é comparado.
 */
class CreateTradeWithOfferUseCase
{
    public function __construct(
        private readonly SupplierService $supplierService,
        private readonly BundleService $bundleService,
        private readonly TradeLineFlagsService $flagsService,
        private readonly KeyCalculationService $calculationService,
        private readonly TradeOfferService $offerService,
    ) {}

    /**
     * @return Trade|null a trade criada, com a primeira oferta — ou a que o mesmo pedido já criou;
     *                    `null` quando não há o que ofertar
     *
     * @throws CurrencyPriceUnavailable quando falta a cotação da TF2 em euro, de onde toda oferta parte
     * @throws RequestReusedForAnotherSupplier quando o pedido já criou a trade de outro supplier
     */
    public function execute(TradeWithOfferDTO $data): ?Trade
    {
        $existing = $this->tradeOfRequest($data);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->create($data);
        } catch (UniqueConstraintViolationException $e) {
            // Duas chamadas do mesmo pedido ao mesmo tempo: as duas passaram pela
            // busca acima, e o índice único barrou a segunda. A trade da primeira
            // é a resposta. Outra violação (sem trade do pedido) segue adiante.
            return $this->tradeOfRequest($data) ?? throw $e;
        }
    }

    /**
     * A trade que o pedido já criou, com a oferta vigente — `null` sem pedido
     * ou quando ele ainda não criou nenhuma.
     *
     * @throws RequestReusedForAnotherSupplier quando a trade é de outro supplier
     */
    private function tradeOfRequest(TradeWithOfferDTO $data): ?Trade
    {
        if ($data->requestUuid === null) {
            return null;
        }

        $trade = Trade::with(['currentOffer', 'supplier'])->where('request_uuid', $data->requestUuid)->first();

        if ($trade !== null && $trade->supplier?->steam_id !== $data->supplierSteamId) {
            throw RequestReusedForAnotherSupplier::for($data->requestUuid);
        }

        return $trade;
    }

    /**
     * @throws CurrencyPriceUnavailable
     */
    private function create(TradeWithOfferDTO $data): ?Trade
    {
        // Sem cotação na moeda pedida, a trade nasce em TF2 (moeda que o supplier
        // da Steam sempre aceita). Decidido antes de gravar, porque a moeda é
        // fato da trade.
        $currency = $data->currency->orTf2WithoutPrice($this->calculationService->getTf2Price($data->currency));

        $evaluated = $this->flagsService->markResearched($data->games);
        $lines = TradeLineBuilder::fromResearch(
            $evaluated,
            $this->bundleService->recentBundleByGameNames(array_column($data->games, 'name')),
        );

        DB::beginTransaction();

        try {
            $supplier = $this->supplierService->resolveBySteamId($data->supplierSteamId);

            $trade = Trade::create([
                'supplier_id' => $supplier?->id,
                'currency' => $currency,
                'title' => $data->title ?: ($supplier?->name ?: null),
                'list_code' => $data->listCode,
                'date' => now()->format('Y-m-d'),
            ]);

            $trade->lines()->createMany($lines);

            // Toda trade nasce com credencial de entrega — ver docs/adr/0008.
            // O pedido vai junto: é a chave que a repetição dele vai procurar.
            // Gravado **antes** da oferta de propósito: numa corrida, é aqui que
            // o índice único barra a segunda chamada, antes de ela calcular e
            // gravar uma oferta que seria desfeita.
            $trade->forceFill([...DeliveryCredential::issue(), 'request_uuid' => $data->requestUuid])->save();

            $offer = $this->offerService->recordInitial($trade);

            if ($offer === null) {
                DB::rollBack();

                return null;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $trade->setRelation('currentOffer', $offer);
    }
}
