<?php

namespace App\Observers;

use App\Models\Game;
use App\Services\GameService;
use Illuminate\Support\Facades\Log;

class GameObserver
{
    /**
     * Handle the Game "created" event.
     */
    public function created(Game $game): void
    {
        //
    }

    /**
     * Handle the Game "updated" event.
     */
    public function updated(Game $game): void
    {
        if (empty($game->id_gamivo)) {
            $gameService = new GameService();
            $id_gamivo = $gameService->getIdGamivo($game->name, $game->region);
            if ($id_gamivo) {
                // Usa saveQuietly para evitar loop infinito (não dispara eventos)
                $game->id_gamivo = $id_gamivo;
                $game->saveQuietly();
            }
        }
    }

    /**
     * Handle the Game "deleted" event.
     */
    public function deleted(Game $game): void
    {
        //
    }

    /**
     * Handle the Game "restored" event.
     */
    public function restored(Game $game): void
    {
        //
    }

    /**
     * Handle the Game "force deleted" event.
     */
    public function forceDeleted(Game $game): void
    {
        //
    }
}
