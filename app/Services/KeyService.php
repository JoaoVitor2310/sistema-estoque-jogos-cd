<?php

namespace App\Services;

use App\Domain\Keys\KeyPriceAging;
use App\Models\Venda_chave_troca;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KeyService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Ajusta o preço mínimo de keys em limbo (listadas há 12+ meses sem venda).
     * Consulta o preço atual no mercado Gamivo e delega o cálculo ao Domain.
     */
    public function checkLimboKeys(): void
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
            $actualPrice = $this->getActualPrice($game->gamivo_id);

            if (! $actualPrice['success']) {
                continue;
            }

            $game->minApiGamivo = KeyPriceAging::calculateLimboPrice(
                individualCost: (float) $game->individual_cost,
                actualMarketPrice: (float) $actualPrice['price'],
            );

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
