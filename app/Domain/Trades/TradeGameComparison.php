<?php

namespace App\Domain\Trades;

class TradeGameComparison
{
    /**
     * Retorna true se a lista de nomes de jogos do request atual difere
     * dos nomes armazenados nas rows da trade anterior.
     *
     * @param  array<int, array{name: string, ...}>  $currentGames
     * @param  array<int, array{name: string, ...}>  $previousRows
     */
    public static function hasChanged(array $currentGames, array $previousRows): bool
    {
        $current = collect($currentGames)->pluck('name')->sort()->values()->all();
        $previous = collect($previousRows)->pluck('name')->sort()->values()->all();

        return $current !== $previous;
    }
}
