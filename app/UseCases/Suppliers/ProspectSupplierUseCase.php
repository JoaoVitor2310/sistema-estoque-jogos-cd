<?php

namespace App\UseCases\Suppliers;

use App\Domain\Enums\PurchaseChannel;
use App\Domain\Enums\SupplierCategory;
use App\Domain\Enums\TradeCurrency;
use App\Domain\Pricing\CurrencyPriceUnavailable;
use App\Domain\Pricing\IncomeCalculator;
use App\Domain\Pricing\SupplierOffer;
use App\Domain\Pricing\ValueObjects\SupplierOfferLine;
use App\Domain\Trades\CommentPolicy;
use App\Domain\Trades\DeliveryCredential;
use App\Domain\Trades\TradeGameComparison;
use App\Domain\Trades\TradeLineBuilder;
use App\Domain\Trades\TradeLineFlags;
use App\Models\Supplier;
use App\Models\Trade;
use App\Models\TradeOffer;
use App\Services\Bundles\BundleService;
use App\Services\Keys\KeyCalculationService;
use App\Services\Suppliers\SupplierService;
use App\Services\Trades\TradeLineFlagsService;
use App\Services\Trades\TradeOfferService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ProspectSupplierUseCase
{
    /** Chave da oferta avaliada de cada jogo: só circula aqui dentro, nunca sai na resposta. */
    private const OFFER_KEY = 'offer';

    public function __construct(
        private readonly SupplierService $supplierService,
        private readonly KeyCalculationService $calculationService,
        private readonly BundleService $bundleService,
        private readonly TradeLineFlagsService $flagsService,
        private readonly TradeOfferService $offerService,
    ) {}

    /**
     * @param  array<int, array{name: string, market_price_euro: float, popularity: int, region: string|null, gamivo_id?: string|null}>  $games
     * @return array<string, mixed> `profitable`, `total_tf2_price`, `is_added`, `last_commented_at`, `games_changed`,
     *                              `should_comment` e, quando a moeda não é TF2, `offer_currency`/`total_offer_price`
     *                              (e `offer_price` em cada item de `profitable`)
     *
     * @throws CurrencyPriceUnavailable quando há comentário a postar e a TF2 não tem cotação na moeda pedida
     */
    public function execute(string $steamId, array $games, ?string $listCode = null, TradeCurrency $currency = TradeCurrency::Tf2): array
    {
        // O lucro é o da categoria do supplier, o mesmo com que a primeira
        // oferta é gravada: o comentário e a oferta são a mesma coisa. Supplier
        // novo não tem categoria (lucro normal); o bloqueado não tem lucro
        // inicial, então nada é ofertado e não há comentário.
        $profitPercent = SupplierCategory::initialProfitPercent(
            Supplier::where('steam_id', $steamId)->first()?->category,
        );

        // `profitable` é o comentário que o price_researcher posta na lista do
        // supplier — cada item vira uma linha "Jogo --- X TF2". Por isso o jogo
        // encalhado e o jogo em giveaway são tirados **daqui**: não ofertamos o
        // que já temos parado nem pagamos preço normal pelo que acabou de sair
        // de graça. Os dois continuam virando linha da trade, marcados
        // (docs/adr/0013 e docs/adr/0014).
        $evaluated = $profitPercent === null
            ? []
            : $this->flagsService->markResearched($this->evaluateProfitability($games, $profitPercent));

        $offered = array_values(array_filter($evaluated, TradeLineFlags::isOfferable(...)));

        $previousTrade = $listCode
            ? Trade::with('lines')
                ->where('list_code', $listCode)
                ->whereNotNull('last_commented_at')
                ->latest('last_commented_at')
                ->first()
            : null;

        $lastCommentedAt = $previousTrade?->last_commented_at;
        $previousNames = $previousTrade?->lines->pluck('game_name')->filter()->all() ?? [];

        $gamesChanged = $previousTrade !== null
            && TradeGameComparison::hasChanged(array_column($games, 'name'), $previousNames);

        // Sobre o que seria ofertado, não sobre a lista inteira: lista em que
        // tudo está encalhado ou em giveaway não tem comentário a postar, e sem
        // comentário não há trade.
        $shouldComment = CommentPolicy::shouldComment($offered, $gamesChanged, $lastCommentedAt);

        // A cotação é resolvida uma vez por prospecção, não por jogo, e **antes** de
        // qualquer gravação: oferta que não se consegue converter não deixa trade
        // nem comentário registrado. Sem comentário a postar, a falta de cotação
        // não é erro — não há oferta a mostrar.
        $currencyPrice = $this->calculationService->getTf2Price($currency);

        if ($shouldComment && ! $currency->hasTf2Price($currencyPrice)) {
            throw CurrencyPriceUnavailable::for($currency);
        }

        // Só depois da checagem acima: a falha de cotação não deixa nada gravado,
        // nem o supplier.
        $record = $this->supplierService->upsert([
            'steam_id' => $steamId,
            'url' => 'https://steamcommunity.com/profiles/'.$steamId,
        ]);

        $offer = null;

        if ($shouldComment) {
            // Dentro do `if` e fora da transação: a prospecção avalia muitos
            // perfis e comenta poucos, então resolver antes cobraria uma query
            // por perfil avaliado em vez de por trade criada.
            $bundleMap = $this->bundleService->recentBundleByGameNames(array_column($evaluated, 'name'));

            // A trade registra a lista pesquisada inteira, ofertada ou não: o
            // jogo encalhado ou em giveaway vira linha marcada, para a equipe
            // ver o que foi deixado de fora da oferta.
            $lines = TradeLineBuilder::fromResearch($evaluated, $bundleMap);

            $offer = $this->recordTradeWithOffer($record, $listCode, $lines, $currency);

            // A avaliação acima não aplica o piso da moeda nem parte do preço já
            // arredondado da linha; a oferta gravada aplica. Se nada sobrou
            // nela, não há o que comentar, e a trade não fica para trás.
            $shouldComment = $offer !== null;
        }

        $hasCurrencyPrice = $currency->hasTf2Price($currencyPrice);

        // Com a oferta gravada, o comentário **é** ela. Sem comentário a postar,
        // a lista é só a avaliação, convertida quando há cotação.
        $profitable = $offer !== null
            ? $this->profitableFromOffer($offer, $evaluated, $currency)
            : array_map(fn (array $game) => $this->profitableItem(
                $game,
                $currency->isCash() && $hasCurrencyPrice ? $game[self::OFFER_KEY]->priceIn($currency, $currencyPrice) : null,
            ), $offered);

        $cashOffer = $currency->isCash() && $hasCurrencyPrice ? [
            'offer_currency' => $currency->value,
            // Soma dos valores já arredondados por jogo: o "Total" do comentário
            // tem de bater com as linhas que o fornecedor lê.
            'total_offer_price' => round(array_sum(array_column($profitable, 'offer_price')), 2),
        ] : [];

        return [
            // Sem as marcas: para quem recebe, `profitable` é só a lista a ofertar.
            // O que ficou de fora é assunto nosso, e está na trade.
            'profitable' => $profitable,
            // Soma só do que é ofertado: é o "Total" do rodapé do comentário, e
            // um total que não bate com as linhas listadas é oferta errada.
            'total_tf2_price' => round(array_sum(array_column($profitable, 'tf2_price')), 2),
            'is_added' => (bool) $record->is_added,
            'last_commented_at' => $lastCommentedAt,
            'games_changed' => $gamesChanged,
            'should_comment' => $shouldComment,
            ...$cashOffer,
        ];
    }

    /**
     * Cria a trade da lista com a primeira rodada da oferta, numa transação só.
     *
     * `null` quando a oferta gravada não tem nenhum jogo: a transação é desfeita
     * e nada fica gravado, como no CreateTradeWithOfferUseCase.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function recordTradeWithOffer(Supplier $supplier, ?string $listCode, array $lines, TradeCurrency $currency): ?TradeOffer
    {
        DB::beginTransaction();

        try {
            $trade = Trade::create([
                'supplier_id' => $supplier->id,
                'currency' => $currency,
                'list_code' => $listCode,
                'last_commented_at' => now(),
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

        return $offer;
    }

    /**
     * A lista do comentário a partir da oferta gravada: os jogos e os valores
     * que o supplier lê são exatamente os da primeira rodada da trade.
     *
     * Em TF2 o comentário lê `tf2_price`, que é o valor gravado. Em dinheiro
     * ele lê `offer_price` (o valor gravado), e `tf2_price` vai como
     * referência, da avaliação.
     *
     * @param  array<int, array<string, mixed>>  $evaluated  na ordem das linhas da trade
     * @return array<int, array<string, mixed>>
     */
    private function profitableFromOffer(TradeOffer $offer, array $evaluated, TradeCurrency $currency): array
    {
        $offer->loadMissing('lines.tradeLine');

        // A posição da linha é o índice do jogo em `$evaluated` reindexado:
        // o TradeLineBuilder numera pela mesma lista, com `array_values`.
        $evaluated = array_values($evaluated);

        return $offer->lines->map(function ($offerLine) use ($evaluated, $currency) {
            $game = $evaluated[$offerLine->tradeLine->position];

            return $currency->isCash()
                ? $this->profitableItem($game, $offerLine->price)
                : ['tf2_price' => $offerLine->price] + $this->profitableItem($game, null);
        })->values()->all();
    }

    /**
     * Um jogo da lista a ofertar, sem as marcas e sem a oferta exata.
     *
     * @param  array<string, mixed>  $game
     * @return array<string, mixed>
     */
    private function profitableItem(array $game, ?float $offerPrice): array
    {
        $item = Arr::except($game, [...TradeLineFlags::COLUMNS, self::OFFER_KEY]);

        if ($offerPrice !== null) {
            $item['offer_price'] = $offerPrice;
        }

        return $item;
    }

    /**
     * @param  array<int, array{name: string, market_price_euro: float, popularity: int, region: string|null, gamivo_id?: string|null}>  $games
     * @return array<int, array{name: string, market_price_euro: float, popularity: int, region: string|null, gamivo_id: string|null, tf2_price: float, offer: SupplierOfferLine}>
     */
    private function evaluateProfitability(array $games, int $profitPercent): array
    {
        $fee = $this->calculationService->getMarketplaceFee();
        $tf2Price = $this->calculationService->getTf2EuroPrice();

        $profitable = [];

        foreach ($games as $game) {
            $netIncome = IncomeCalculator::forGamivo((float) $game['market_price_euro'], $fee);
            $offer = SupplierOffer::line($netIncome, $profitPercent, $tf2Price, PurchaseChannel::SupplierTrade);

            if ($offer === null) {
                continue;
            }

            $profitable[] = [
                'name' => $game['name'],
                'market_price_euro' => (float) $game['market_price_euro'],
                'popularity' => (int) $game['popularity'],
                'region' => $game['region'] ?? null,
                'gamivo_id' => $game['gamivo_id'] ?? null,
                // Mesmo arredondamento e piso da oferta gravada: nunca "0.00 TF2".
                'tf2_price' => $offer->priceIn(TradeCurrency::Tf2, TradeCurrency::TF2_UNIT_PRICE),
                // A oferta exata, de onde sai o valor em dinheiro: converter o
                // `tf2_price` já arredondado compõe dois arredondamentos.
                self::OFFER_KEY => $offer,
            ];
        }

        return $profitable;
    }
}
