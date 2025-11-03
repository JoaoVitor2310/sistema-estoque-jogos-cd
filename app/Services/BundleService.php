<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\Game;
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

            // TODO: Criar bot pra preencher id steamcharts

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
            
            $topTier = max($api_bundle['tiers']);

            $price_dolar = $topTier['currency'] === 'USD' ? $topTier['price'] : null;

            if (!$price_dolar) {
                $converted_price = $APIService->convertCurrency($topTier['currency'], 'USD', $topTier['price']);
                if ($converted_price['success']) {
                    $price_dolar = $converted_price['amount'];
                } else {
                    Log::error('Não foi possível converter preço do bundle: ' . $api_bundle['title']);
                    // TODO: Enviar email com erro
                }
            };

            $bundle->update([
                'price_dolar' => $price_dolar ?? $topTier['price'],
            ]);

            // Buscar os jogos do bundle
            $gameIds = [];
            foreach ($topTier['games'] as $api_game) {
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
}
