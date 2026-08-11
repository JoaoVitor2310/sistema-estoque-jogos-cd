<?php

namespace App\Services\Games;

use App\Domain\Games\GameNameNormalizer;
use App\Models\Game;
use App\Models\Key;

/**
 * Infraestrutura para operações sobre jogos.
 *
 * Responsabilidades:
 *  - Lookup de gamivo_id (em keys e games)
 *  - Preenchimento de gamivo_id na tabela games
 *  - Criação de jogo na tabela games (quando não existe)
 */
class GameService
{
    /**
     * Procura o gamivo_id pelo nome do jogo e região.
     * Busca primeiro nas keys existentes, depois na tabela games.
     */
    public function getIdGamivo(string $gameName, ?string $region): string|false
    {
        $key = Key::select('gamivo_id')
            ->whereRaw('LOWER("game_name") = LOWER(?)', [$gameName])
            ->where('region', $region)
            ->whereNotNull('gamivo_id')
            ->first();

        if ($key) {
            return $key->gamivo_id;
        }

        $game = Game::select('gamivo_id')
            ->whereRaw('LOWER("name") = LOWER(?)', [$gameName])
            ->where('region', $region)
            ->whereNotNull('gamivo_id')
            ->first();

        return $game?->gamivo_id ?? false;
    }

    /**
     * Procura o steam_id pelo nome do jogo e região.
     * Busca primeiro nas keys existentes, depois na tabela games.
     */
    public function getSteamId(string $gameName, ?string $region): string|false
    {
        $key = Key::select('steam_id')
            ->whereRaw('LOWER("game_name") = LOWER(?)', [$gameName])
            ->where('region', $region)
            ->whereNotNull('steam_id')
            ->first();

        if ($key) {
            return $key->steam_id;
        }

        $game = Game::select('steam_id')
            ->whereRaw('LOWER("name") = LOWER(?)', [$gameName])
            ->where('region', $region)
            ->whereNotNull('steam_id')
            ->first();

        return $game?->steam_id ?? false;
    }

    /**
     * Preenche o steam_id na tabela games quando ainda não está cadastrado.
     */
    public function fillSteamId(string $gameName, ?string $region, string $steamId): void
    {
        $game = Game::whereRaw('LOWER("name") = LOWER(?)', [$gameName])
            ->where('region', $region)
            ->whereNull('steam_id')
            ->first();

        if ($game) {
            $game->steam_id = $steamId;
            $game->save();
        }
    }

    /**
     * Preenche o gamivo_id na tabela games quando ainda não está cadastrado.
     */
    public function fillIdGamivo(string $gameName, ?string $region, string $idGamivo): void
    {
        $game = Game::whereRaw('LOWER("name") = LOWER(?)', [$gameName])
            ->where('region', $region)
            ->whereNull('gamivo_id')
            ->first();

        if ($game) {
            $game->gamivo_id = $idGamivo;
            $game->save();
        }
    }

    /**
     * Cria o registro na tabela games se ainda não existir.
     *
     * Usa firstOr() com LOWER() para evitar duplicatas por diferença de casing,
     * já que não há garantia de normalização na entrada.
     * firstOrCreate() puro usaria match exato — incorreto aqui.
     *
     * @param  array{game_name: string, region: string|null, gamivo_id: string|null, steam_id: string|null}  $game
     */
    public function createGameIfDontExists(array $game): void
    {
        Game::whereRaw('LOWER("name") = LOWER(?)', [$game['game_name']])
            ->where('region', $game['region'])
            ->firstOr(fn () => Game::create([
                'name' => $game['game_name'],
                'normalized_name' => GameNameNormalizer::normalize($game['game_name']),
                'region' => $game['region'],
                'gamivo_id' => $game['gamivo_id'],
                'steam_id' => $game['steam_id'] ?? null,
            ]));
    }
}
