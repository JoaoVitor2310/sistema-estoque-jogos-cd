<?php

namespace App\UseCases\Trades;

use App\Domain\Pricing\CurrencyPriceUnavailable;
use App\Domain\Trades\DeliveryCredential;
use App\Domain\Trades\TradeLineBuilder;
use App\Models\Trade;
use App\Services\Bundles\BundleService;
use App\Services\Keys\KeyCalculationService;
use App\Services\Suppliers\SupplierService;
use App\Services\Trades\TradeLineFlagsService;
use App\Services\Trades\TradeOfferService;
use App\UseCases\Trades\DTO\TradeWithOfferDTO;
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
     * @return Trade|null a trade criada, com a primeira oferta; `null` quando não há o que ofertar
     *
     * @throws CurrencyPriceUnavailable quando falta a cotação da TF2 em euro, de onde toda oferta parte
     */
    public function execute(TradeWithOfferDTO $data): ?Trade
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
            $trade->forceFill(DeliveryCredential::issue())->save();

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
