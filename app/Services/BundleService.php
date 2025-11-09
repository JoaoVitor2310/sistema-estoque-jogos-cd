<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\Game;
use App\Models\Recursos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class BundleService
{
    /**
     * Sync bundles from GGDeals API to database
     * 
     * @return void
     */
    public function  getBundlesFromAPI()
    {
        try {
            $APIService = new APIService();
            $response = $APIService->getBundles();
            if (!$response['success']) {
                Log::error('Erro ao buscar bundles: ' . $response['message']);
            }

            $bundles = $response['data'];
            DB::beginTransaction();

            $this->createBundlesFromAPI($bundles, $APIService);

            DB::commit();

            Log::info('Bundles atualizados com sucesso! Total: ' . count($bundles));
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao processar bundles: ' . $e->getMessage() . ' | Linha: ' . $e->getLine() . ' | Arquivo: ' . $e->getFile());
        }
    }

    /**
     * Process and create/update bundles in database
     * 
     * @param array $bundles List of bundles from API
     * @param APIService $APIService API service instance
     * @return void
     */
    public function createBundlesFromAPI($bundles, $APIService)
    {
        foreach ($bundles as $api_bundle) {
            $type = stripos($api_bundle['title'], 'Choice') !== false ? 'choice' : 'bundle';


            $bundle = Bundle::firstOrCreate(
                ['url' => $api_bundle['url']],
                [
                    'name' => $api_bundle['title'],
                    'type' => $type,
                    'release_date' => $api_bundle['dateFrom'],
                    'end_date' => $api_bundle['dateTo'],
                ]
            );

            if ($type == 'choice' && $bundle->wasRecentlyCreated) {
                Mail::raw('Novo Humble Bundle Choice detectado: ' . $bundle->name . "\n\nURL: " . $bundle->url, function ($message) use ($bundle) {
                    $message->to('carcadeals@gmail.com')
                        ->subject('🎮 Novo Humble Bundle Choice: ' . $bundle->name);
                });
            }

            $topTierBundle = max($api_bundle['tiers']);

            if(!$this->getBundlePrices($topTierBundle, $APIService, $api_bundle, $bundle)) continue;


            // Buscar os jogos do bundle
            $gameIds = [];
            foreach ($topTierBundle['games'] as $api_game) {
                $game = Game::firstOrCreate(
                    ['name' => $api_game['title']]
                );

                // TODO: Procurar idGamivo através do id steam para preencher o id_gamivo
                // if($game->id_gamivo == '') {
                //     $gameService = new GameService();
                //     $idGamivo = $gameService->getIdGamivo($api_game['title'], $api_game['region']);
                //     if ($idGamivo) $game->id_gamivo = $idGamivo;
                // }

                $gameIds[] = $game->id;
            }

            // Inserir jogo no bundle 
            $bundle->games()->syncWithoutDetaching($gameIds);
        }
    }

    /**
     * Get price and minimum price of the bundle
     * 
     * @param array $topTierBundle Top tier bundle data from API
     * @param APIService $APIService API service instance
     * @param array $api_bundle Full bundle data from API
     * @param Bundle $bundle Bundle model instance
     * @return bool
     */
    private function getBundlePrices(array $topTierBundle, APIService $APIService, array $api_bundle, Bundle $bundle): bool
    {
        $price_dolar = $topTierBundle['currency'] === 'USD' ? $topTierBundle['price'] : null;

        if (!$price_dolar) {
            $converted_price = $APIService->convertCurrency($topTierBundle['currency'], 'USD', $topTierBundle['price']);
            if ($converted_price['success']) {
                $price_dolar = $converted_price['amount'];
            } else {
                Log::error('Não foi possível converter preço do bundle: ' . $api_bundle['title']);
                Mail::raw('Não foi possível converter preço do bundle: ' . $api_bundle['title'], function ($message) use ($api_bundle) {
                    $message->to('carcadeals@gmail.com')
                        ->subject('🎮 Erro ao converter preço do bundle: ' . $api_bundle['title']);
                });
                return false;
            }
        }

        $bundle->price_dolar = $price_dolar ?? $topTierBundle['price'];

        $tf2_price_dolar = Recursos::where('name', 'TF2')->first()->preco_dolar;

        $bundle->minimum_price_tf2 = $bundle->price_dolar / $tf2_price_dolar;

        $bundle->save();

        return true;
    }
}
