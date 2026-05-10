<?php

namespace App\Services\Games;

use App\Models\Game;
use Illuminate\Database\Eloquent\Collection;

/**
 * Queries de leitura e escrita simples sobre a tabela games.
 */
class GameRepository
{
    /**
     * Retorna todos os jogos com steam_id cadastrado, para atualização de popularidade.
     *
     * @return Collection<int, Game>
     */
    public function getGamesForPopularityUpdate(): Collection
    {
        return Game::whereNotNull('steam_id')->get(['id', 'steam_id', 'name']);
    }

    /**
     * Atualiza a popularidade de um jogo no banco.
     */
    public function updatePopularity(int $id, int $popularity): void
    {
        Game::where('id', $id)->update(['popularity' => $popularity]);
    }
}
