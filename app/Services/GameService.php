<?php

namespace App\Services;

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
        $game = Venda_chave_troca::select('idGamivo')->where('nomeJogo', $nomeJogo)->get();

        // Filtra os resultados para remover os idGamivo que são null
        $filteredGame = $game->filter(function ($item) {
            return !is_null($item->idGamivo);
        });

        // Verifica se há resultados após o filtro
        if ($filteredGame->isEmpty()) {
            return false;  // Nenhum idGamivo válido encontrado
        } else {
            // Retorna o primeiro idGamivo que não é null
            return $filteredGame->first()->idGamivo;
        }
    }
}
