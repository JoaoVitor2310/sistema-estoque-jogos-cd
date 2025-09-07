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

    public function fillIdGamivo(string $nomeJogo)
    {
        // Procura nas keys da tabela venda-chave-troca
        $game = Venda_chave_troca::select('idGamivo')->where('nomeJogo', $nomeJogo)->whereNotNull('idGamivo')->first();
        if ($game) return $game->idGamivo;

        // Procura nos dados de jogos gerais
        $game = Game::select('id_gamivo')->where('name', $nomeJogo)->whereNotNull('id_gamivo')->first();
        if ($game) return $game->id_gamivo;

        return false;
    }
}
