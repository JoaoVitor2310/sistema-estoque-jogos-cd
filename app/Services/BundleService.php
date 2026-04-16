<?php

namespace App\Services;

use App\Domain\Bundles\BundleTypeResolver;
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
    public function getBundlesFromAPI()
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
            $type = BundleTypeResolver::resolve($api_bundle['title']);

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
                Mail::raw('Choice novo detectado: ' . $bundle->name . "\n\nURL: " . $bundle->url, function ($message) use ($bundle) {
                    $message->to('carcadeals@gmail.com')
                        ->subject('🎮 Choice novo: ' . $bundle->name);
                });
            }

            $topTierBundle = max($api_bundle['tiers']);

            if (!$this->getBundlePrices($topTierBundle, $APIService, $api_bundle, $bundle)) continue;

            // Buscar os jogos do bundle
            $games = [];
            foreach ($topTierBundle['games'] as $api_game) {
                $game = Game::firstOrCreate(
                    ['name' => $api_game['title']]
                );

                $games[] = $game;
            }

            // Insert games in bundle
            $gameIds = collect($games)->pluck('id')->toArray();
            $bundle->games()->syncWithoutDetaching($gameIds);

            if ($bundle->wasRecentlyCreated) $this->getBundleLaunchPrices($games, $bundle);
        }
    }

    /**
     * Get price and minimum price in tf2 of the bundle
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

    /**
     * Get game prices when the bundle is launched
     * 
     * @param array $gameNames List of game names
     * @param Bundle $bundle New bundle model instance
     * @return void
     */
    private function getBundleLaunchPrices(array $games, Bundle $bundle): void
    {
        $gameNames = collect($games)->pluck('name')->toArray();
        try {
            // Get game price in release date using Price Researcher API(https://github.com/JoaoVitor2310/price-cd)
            $response = Http::timeout(3200)->post(
                config('services.price_researcher.base_url') . '/api/games/search',
                [
                    'minPopularity' => 0,
                    'gameNames' => $gameNames,
                    'checkGamivoOffer' => false,
                ]
            );
            
            $responseData = $response->json();

            if (isset($responseData['success']) && $responseData['success'] && isset($responseData['data']['games'])) {
                $apiGames = $responseData['data']['games'];

                foreach ($apiGames as $apiGame) {
                    $matchedGame = collect($games)->first(function ($game) use ($apiGame) {
                        return $game->name === $apiGame['name'];
                    });

                    if ($matchedGame && isset($apiGame['GamivoPrice'])) {
                        // Convert price from European format (0,09) to decimal (0.09)
                        $price = str_replace(',', '.', $apiGame['GamivoPrice']);

                        // Update bundle_launch_price in pivot table
                        $bundle->games()->updateExistingPivot($matchedGame->id, [
                            'bundle_launch_price' => $price
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Erro ao buscar preços dos jogos: ' . $e->getMessage() . ' | Arquivo: ' . $e->getFile() . ' | Linha: ' . $e->getLine());
        }
    }

    /**
     * Get bundles with filters and pagination
     * 
     * @param array $filters Filters to apply
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getBundlesWithFilters(array $filters)
    {
        $query = Bundle::with([
            'games' => function ($query) {
                $query->orderBy('name', 'asc');
            }
        ]);

        $this->applyFilters($query, $filters);

        $limit = $filters['limit'] ?? 20;
        return $query->orderBy('id', 'desc')->paginate($limit);
    }

    /**
     * Apply filters to the query
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query Query builder instance
     * @param array $filters Filters to apply
     * @return void
     */
    private function applyFilters($query, array $filters): void
    {
        foreach ($filters as $key => $value) {
            if (!$value) continue;

            if (is_array($value)) {
                $query->whereIn($key, $value);
            } else if (is_string($value)) {
                $this->applyStringFilter($query, $key, $value);
            } else if (is_bool($value) && str_starts_with($key, 'search_')) {
                $query->whereNull($key);
            } else {
                $query->where($key, $value);
            }
        }
    }

    /**
     * Apply string filters (range, search, etc)
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query Query builder instance
     * @param string $key Filter key
     * @param string $value Filter value
     * @return void
     */
    private function applyStringFilter($query, string $key, string $value): void
    {
        // Range filters (minimum values)
        if (in_array($key, ['release_date_start', 'minimum_price_tf2_min', 'price_dolar_min'])) {
            $actualKey = str_replace(['_start', '_min'], '', $key);
            $query->where($actualKey, '>=', $value);
            return;
        }

        // Range filters (maximum values)
        if (in_array($key, ['release_date_end', 'minimum_price_tf2_max', 'price_dolar_max'])) {
            $actualKey = str_replace(['_end', '_max'], '', $key);
            $query->where($actualKey, '<=', $value);
            return;
        }

        // Game name filter (relationship)
        if ($key === 'game_name') {
            $query->whereHas('games', function ($query) use ($value) {
                $query->where('name', 'ILIKE', "%" . $value . "%");
            });
            return;
        }

        // Default string filter (LIKE)
        $query->where($key, 'ILIKE', "%" . $value . "%");
    }
}
