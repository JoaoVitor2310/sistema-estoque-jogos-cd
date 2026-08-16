<?php

namespace App\Domain\Trades;

class TradeGameComparison
{
    /**
     * Retorna true se a lista de nomes de jogos do request atual difere
     * dos nomes da trade anterior. Só os nomes importam: preço e popularidade
     * mudam a toda pesquisa e não são motivo para recomentar.
     *
     * @param  string[]  $currentNames
     * @param  string[]  $previousNames
     */
    public static function hasChanged(array $currentNames, array $previousNames): bool
    {
        $current = collect($currentNames)->sort()->values()->all();
        $previous = collect($previousNames)->sort()->values()->all();

        return $current !== $previous;
    }
}
