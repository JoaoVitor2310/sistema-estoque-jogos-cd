<?php

namespace App\UseCases\Bundles;

use App\Domain\Bundles\BundleTypeResolver;
use App\Domain\Bundles\LaunchPriceResearch;
use App\Domain\Games\GameNameNormalizer;
use App\Mail\BundlePriceConversionFailedMail;
use App\Mail\NewChoiceBundleMail;
use App\Models\Asset;
use App\Models\Bundle;
use App\Models\Game;
use App\Services\External\BundleResearchRequest;
use App\Services\External\CurrencyConversionService;
use App\Services\External\GgDealsApiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Orquestra a sincronização de bundles com a API GGDeals.
 *
 * Responsabilidades:
 *  - Buscar bundles na API GGDeals (GgDealsApiService)
 *  - Criar/atualizar Bundle e Games no banco (Eloquent)
 *  - Converter moedas para EUR quando necessário (CurrencyConversionService)
 *  - Buscar preços de lançamento de jogos novos (price_researcher)
 *  - Enviar alertas de e-mail para novos Choices
 *  - Gerenciar a transação que envolve todo o fluxo
 */
class SyncBundlesFromApiUseCase
{
    /**
     * Teto de espera do preço de lançamento: o price_researcher pesquisa o
     * bundle inteiro dentro da requisição, um jogo por vez, na mesma fila das
     * outras pesquisas — um bundle grande atrás de um job longo leva minutos.
     */
    public const LAUNCH_PRICES_TIMEOUT_SECONDS = 3200;

    public function __construct(
        private readonly GgDealsApiService $ggDealsApi,
        private readonly CurrencyConversionService $currencyService,
    ) {}

    public function execute(): void
    {
        try {
            $response = $this->ggDealsApi->getBundles();

            if (! $response['success']) {
                Log::error('Erro ao buscar bundles: '.$response['message']);

                return;
            }

            DB::beginTransaction();
            $this->syncBundles($response['data']);
            DB::commit();

            Log::info('Bundles atualizados com sucesso! Total: '.count($response['data']));
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error(
                'Erro ao processar bundles: '.$e->getMessage()
                .' | Linha: '.$e->getLine()
                .' | Arquivo: '.$e->getFile()
            );
        }
    }

    // -------------------------------------------------------------------------
    // Orquestração por bundle
    // -------------------------------------------------------------------------

    private function syncBundles(array $bundles): void
    {
        foreach ($bundles as $apiBundle) {
            $type = BundleTypeResolver::resolve($apiBundle['title']);

            $bundle = Bundle::firstOrCreate(
                ['url' => $apiBundle['url']],
                [
                    'name' => $apiBundle['title'],
                    'type' => $type,
                    'release_date' => $apiBundle['dateFrom'],
                    'end_date' => $apiBundle['dateTo'],
                ]
            );

            // Alerta por e-mail ao detectar um novo Choice
            if ($type === 'choice' && $bundle->wasRecentlyCreated) {
                Mail::to(config('app.admin_email'))->send(new NewChoiceBundleMail($bundle));
            }

            $topTierBundle = max($apiBundle['tiers']);

            if (! $this->saveBundlePrices($topTierBundle, $apiBundle, $bundle)) {
                continue;
            }

            $games = collect($topTierBundle['games'])
                ->map(fn ($apiGame) => Game::firstOrCreate(
                    ['name' => $apiGame['title']],
                    ['normalized_name' => GameNameNormalizer::normalize($apiGame['title'])]
                ))
                ->all();

            $bundle->games()->syncWithoutDetaching(
                collect($games)->pluck('id')->toArray()
            );

            // Não só no bundle recém-criado: uma pesquisa que falhou é refeita
            // nas rodadas seguintes, enquanto ainda estiver perto do lançamento.
            $hasLaunchPrice = $bundle->games()->wherePivotNotNull('bundle_launch_price')->exists();
            $releaseDate = Carbon::parse($bundle->release_date ?? $bundle->created_at);

            if (LaunchPriceResearch::isDue($hasLaunchPrice, $releaseDate, now())) {
                $this->fetchLaunchPrices($games, $bundle);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Infraestrutura: preços do bundle
    // -------------------------------------------------------------------------

    /**
     * Converte o preço do top tier para euro, persiste price_euro e minimum_price_tf2.
     * Retorna false e loga erro se a conversão falhar (bundle é pulado).
     *
     * `minimum_price_tf2` é a razão entre o preço do bundle e o de uma TF2 key —
     * quantas keys o bundle custa. As duas pontas são lidas em euro justamente
     * para a razão não depender de duas cotações diferentes.
     */
    private function saveBundlePrices(array $topTierBundle, array $apiBundle, Bundle $bundle): bool
    {
        $priceEuro = $topTierBundle['currency'] === 'EUR'
            ? $topTierBundle['price']
            : null;

        if ($priceEuro === null) {
            $converted = $this->currencyService->convertCurrency(
                $topTierBundle['currency'],
                'EUR',
                $topTierBundle['price']
            );

            if ($converted['success']) {
                $priceEuro = $converted['amount'];
            } else {
                Log::error('Não foi possível converter preço do bundle: '.$apiBundle['title']);
                Mail::to(config('app.admin_email'))->send(
                    new BundlePriceConversionFailedMail($apiBundle['title'])
                );

                return false;
            }
        }

        $tf2PriceEuro = (float) Asset::where('name', 'TF2')->value('price_euro');

        $bundle->price_euro = $priceEuro;
        // Sem cotação da TF2 key na base não há razão a calcular — dividir por
        // zero derrubaria a sincronização inteira; o preço do bundle já vale.
        $bundle->minimum_price_tf2 = $tf2PriceEuro > 0 ? $priceEuro / $tf2PriceEuro : null;
        $bundle->save();

        return true;
    }

    /**
     * Busca no price_researcher o preço de cada jogo no lançamento do bundle
     * e persiste em bundle_games.bundle_launch_price.
     *
     * Falhas de rede ou de resposta são logadas sem interromper o fluxo principal.
     *
     * @param  array<int, Game>  $games
     */
    private function fetchLaunchPrices(array $games, Bundle $bundle): void
    {
        $internalSecret = config('services.price_researcher.internal_secret');

        // Sem segredo o price_researcher responde em modo demo, com só os
        // primeiros jogos: gravaria o preço de um bundle cortado pela metade.
        if (! $internalSecret) {
            Log::error('Price Researcher launch prices: INTERNAL_SECRET não configurado.');

            return;
        }

        $gameNames = collect($games)->pluck('name')->all();

        try {
            $response = Http::timeout(self::LAUNCH_PRICES_TIMEOUT_SECONDS)->post(
                rtrim((string) config('services.price_researcher.base_url'), '/').'/api/games/research',
                BundleResearchRequest::launchPricesPayload($gameNames, $internalSecret)
            );

            $responseData = $response->json() ?? [];

            if ($responseData['demo'] ?? false) {
                Log::error('Price Researcher launch prices recusou o INTERNAL_SECRET (modo demo).');

                return;
            }

            if (! $response->successful() || ! ($responseData['success'] ?? false) || ! is_array($responseData['games'] ?? null)) {
                Log::error('Price Researcher launch prices falhou: HTTP '.$response->status());

                return;
            }

            foreach ($responseData['games'] as $apiGame) {
                $matchedGame = $this->matchGame($games, (string) ($apiGame['name'] ?? ''));

                // Sem casamento o preço se perderia em silêncio: o log é a única
                // pista de que o price_researcher devolveu o nome em outra forma.
                if (! $matchedGame) {
                    Log::warning('Price Researcher launch prices: game not matched in bundle '.$bundle->id.': '.($apiGame['name'] ?? ''));

                    continue;
                }

                if (isset($apiGame['market_price_euro'])) {
                    $bundle->games()->updateExistingPivot($matchedGame->id, [
                        'bundle_launch_price' => (float) $apiGame['market_price_euro'],
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error(
                'Erro ao buscar preços dos jogos: '.$e->getMessage()
                .' | Arquivo: '.$e->getFile()
                .' | Linha: '.$e->getLine()
            );
        }
    }

    /**
     * Acha o jogo do bundle a que um resultado do price_researcher se refere.
     *
     * Nome idêntico primeiro; senão, pelo nome normalizado — o mesmo pipeline
     * do price_researcher, então diferença só de pontuação ou caixa ainda casa.
     *
     * @param  array<int, Game>  $games
     */
    private function matchGame(array $games, string $name): ?Game
    {
        $normalized = GameNameNormalizer::normalize($name);

        return collect($games)->first(fn (Game $game) => $game->name === $name)
            ?? collect($games)->first(fn (Game $game) => GameNameNormalizer::normalize((string) $game->name) === $normalized);
    }
}
