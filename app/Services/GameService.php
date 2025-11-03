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

    public function getIdGamivo(string $nomeJogo, string | null $region)
    {
        // Procura nas keys da tabela venda-chave-troca
        $game = Venda_chave_troca::select('idGamivo')->where('nomeJogo', $nomeJogo)->where('region', $region)->whereNotNull('idGamivo')->first();
        if ($game) return $game->idGamivo;

        // Procura nos dados de jogos gerais
        $game = Game::select('id_gamivo')->where('name', $nomeJogo)->where('region', $region)->whereNotNull('id_gamivo')->first();
        if ($game) return $game->id_gamivo;

        return false;
    }
    
    /**
     * Preenche o idGamivo na tabela Games se ainda não estiver preenchido
     * @param string $gameName
     * @param string|null $region
     * @param string $idGamivo
     * @return void
     */
    public function fillIdGamivo($gameName, $region, $idGamivo)
    {
        // Procura nos dados de jogos gerais
        $game = Game::where('name', $gameName)->where('region', $region)->whereNull('id_gamivo')->first();
        if ($game) {
            $game->id_gamivo = $idGamivo;
            $game->save();
        }
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
