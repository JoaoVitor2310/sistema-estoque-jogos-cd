<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Venda_chave_troca;

class GameService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function fillIdGamivo(string $nomeJogo, string $region)
    {
        // Procura nas keys da tabela venda-chave-troca
        $game = Venda_chave_troca::select('idGamivo')->where('nomeJogo', $nomeJogo)->where('region', $region)->whereNotNull('idGamivo')->first();
        if ($game) return $game->idGamivo;

        // Procura nos dados de jogos gerais
        $game = Game::select('id_gamivo')->where('name', $nomeJogo)->where('region', $region)->whereNotNull('id_gamivo')->first();
        if ($game) return $game->id_gamivo;

        return false;
    }

    public function createGameIfDontExists($game)
    {
        $exists = Game::where('name', $game['nomeJogo'])->where('region', $game['region'])->first();
        if (!$exists) {
            Game::create([
                'name' => $game['nomeJogo'],
                'region' => $game['region'],
                'id_gamivo' => $game['idGamivo'],
            ]);
        }

        return;
    }
}
