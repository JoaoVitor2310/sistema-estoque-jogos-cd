<?php

namespace App\Services;

use App\Models\Venda_chave_troca;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VipService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function runVipLists(): void
    {
        $games = Venda_chave_troca::select([
            'id',
            'key_code',
            'gamivo_id',
            'individual_cost',
            'minApiGamivo',
            'maxApiGamivo',
            'listed_at',
        ])
            ->whereNull('sold_at')
            ->whereNotNull('gamivo_id')
            ->where('listed_at', '<=', now()->subMonths(12))
            ->get();

        foreach ($games as $game) {
            if ($game->id !== 675) {
                continue;
            }
            // Checar preço atual
            $actualPrice = $this->getActualPrice($game->gamivo_id);

            if (! $actualPrice['success']) {
                continue;
            }

            $actualPrice['price'] = 2;

            if ($actualPrice['price'] < $game->individual_cost) {
                // Se o preco atual < individual_cost, minApi = 0,02
                $game->minApiGamivo = 0.02;
            } else {
                // Se não, minApi = preco atual * 0,10
                $game->minApiGamivo = $actualPrice['price'] * 0.10;
            }

            $game->save();
        }
    }

    /**
     * Get the actual price of the game on Gamivo
     *
     * @param  string  $gamivoId
     */
    private function getActualPrice($gamivoId): array
    {
        try {
            $response = Http::get(config('services.carca_api_gamivo.base_url').'/api/products/'.$gamivoId);
            if ($response->successful()) {
                $response = $response->json();

                return ['success' => true, 'price' => $response['actualPrice']['price']];
            } else {
                return ['success' => false, 'price' => null];
            }
        } catch (\Throwable $e) {
            //error log
            Log::error('Error getting actual price of game on Gamivo: '.$e->getMessage().' - '.$e->getLine().' - '.$e->getFile());

            return ['success' => false, 'price' => null];
        }
    }
}
