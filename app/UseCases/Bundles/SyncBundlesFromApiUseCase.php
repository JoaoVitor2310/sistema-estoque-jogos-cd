<?php

namespace App\UseCases\Bundles;

use App\Domain\Bundles\BundleTypeResolver;
use App\Models\Bundle;
use App\Models\Game;
use App\Models\Recursos;
use App\Services\APIService;
use App\Services\External\CurrencyConversionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Orquestra a sincronização de bundles com a API GGDeals.
 *
 * Responsabilidades:
 *  - Buscar bundles na API GGDeals (APIService)
 *  - Criar/atualizar Bundle e Games no banco (Eloquent)
 *  - Converter moedas para USD quando necessário (CurrencyConversionService)
 *  - Buscar preços de lançamento de jogos novos (price_researcher)
 *  - Enviar alertas de e-mail para novos Choices
 *  - Gerenciar a transação que envolve todo o fluxo
 */
class SyncBundlesFromApiUseCase
{
    public function __construct(
        private readonly APIService $apiService,
        private readonly CurrencyConversionService $currencyService,
    ) {}

    public function execute(): void
    {
        try {
            $response = $this->apiService->getBundles();

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
                Mail::raw(
                    'Choice novo detectado: '.$bundle->name."\n\nURL: ".$bundle->url,
                    fn ($message) => $message
                        ->to('carcadeals@gmail.com')
                        ->subject('🎮 Choice novo: '.$bundle->name)
                );
            }

            $topTierBundle = max($apiBundle['tiers']);

            if (! $this->saveBundlePrices($topTierBundle, $apiBundle, $bundle)) {
                continue;
            }

            $games = collect($topTierBundle['games'])
                ->map(fn ($apiGame) => Game::firstOrCreate(['name' => $apiGame['title']]))
                ->all();

            $bundle->games()->syncWithoutDetaching(
                collect($games)->pluck('id')->toArray()
            );

            // Busca preços de lançamento apenas para bundles recém-criados
            if ($bundle->wasRecentlyCreated) {
                $this->fetchLaunchPrices($games, $bundle);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Infraestrutura: preços do bundle
    // -------------------------------------------------------------------------

    /**
     * Converte o preço do top tier para USD, persiste price_dolar e minimum_price_tf2.
     * Retorna false e loga erro se a conversão falhar (bundle é pulado).
     */
    private function saveBundlePrices(array $topTierBundle, array $apiBundle, Bundle $bundle): bool
    {
        $priceDolar = $topTierBundle['currency'] === 'USD'
            ? $topTierBundle['price']
            : null;

        if ($priceDolar === null) {
            $converted = $this->currencyService->convertCurrency(
                $topTierBundle['currency'],
                'USD',
                $topTierBundle['price']
            );

            if ($converted['success']) {
                $priceDolar = $converted['amount'];
            } else {
                Log::error('Não foi possível converter preço do bundle: '.$apiBundle['title']);
                Mail::raw(
                    'Não foi possível converter preço do bundle: '.$apiBundle['title'],
                    fn ($message) => $message
                        ->to('carcadeals@gmail.com')
                        ->subject('🎮 Erro ao converter preço do bundle: '.$apiBundle['title'])
                );

                return false;
            }
        }

        $tf2PriceDolar = Recursos::where('name', 'TF2')->value('preco_dolar');

        $bundle->price_dolar = $priceDolar;
        $bundle->minimum_price_tf2 = $priceDolar / $tf2PriceDolar;
        $bundle->save();

        return true;
    }

    /**
     * Busca no price-researcher o preço de cada jogo no lançamento do bundle
     * e persiste em bundle_games.bundle_launch_price.
     *
     * Falhas de rede ou de resposta são logadas sem interromper o fluxo principal.
     */
    private function fetchLaunchPrices(array $games, Bundle $bundle): void
    {
        $gameNames = collect($games)->pluck('name')->all();

        try {
            $response = Http::timeout(3200)->post(
                config('services.price_researcher.base_url').'/api/games/search',
                [
                    'minPopularity' => 0,
                    'gameNames' => $gameNames,
                    'checkGamivoOffer' => false,
                ]
            );

            $responseData = $response->json();

            if (! ($responseData['success'] ?? false) || ! isset($responseData['data']['games'])) {
                return;
            }

            foreach ($responseData['data']['games'] as $apiGame) {
                $matchedGame = collect($games)->first(fn ($game) => $game->name === $apiGame['name']);

                if ($matchedGame && isset($apiGame['GamivoPrice'])) {
                    // Converte formato europeu (0,09) para decimal (0.09)
                    $price = str_replace(',', '.', $apiGame['GamivoPrice']);
                    $bundle->games()->updateExistingPivot($matchedGame->id, [
                        'bundle_launch_price' => $price,
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
}
