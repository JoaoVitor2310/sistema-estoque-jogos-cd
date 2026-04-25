<?php

namespace App\Services\Games;

use App\Domain\Keys\KeyPriceAging;
use App\Models\Game;
use App\Models\Key;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Infraestrutura para operações sobre jogos.
 *
 * Responsabilidades:
 *  - Lookup de gamivo_id (em keys e games)
 *  - Preenchimento de id_gamivo na tabela games
 *  - Criação de jogo na tabela games (quando não existe)
 *  - Busca de IDs no Steamcharts via price_researcher
 *  - Atualização de preço mínimo da API Gamivo por envelhecimento (delega regra ao Domain)
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

        $game = Game::select('id_gamivo')
            ->whereRaw('LOWER("name") = LOWER(?)', [$gameName])
            ->where('region', $region)
            ->whereNotNull('id_gamivo')
            ->first();

        return $game?->id_gamivo ?? false;
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
     * Preenche o id_gamivo na tabela games quando ainda não está cadastrado.
     */
    public function fillIdGamivo(string $gameName, ?string $region, string $idGamivo): void
    {
        $game = Game::whereRaw('LOWER("name") = LOWER(?)', [$gameName])
            ->where('region', $region)
            ->whereNull('id_gamivo')
            ->first();

        if ($game) {
            $game->id_gamivo = $idGamivo;
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
                'region' => $game['region'],
                'id_gamivo' => $game['gamivo_id'],
                'steam_id' => $game['steam_id'] ?? null,
            ]));
    }

    /**
     * Busca IDs do Steamcharts para jogos que ainda não foram pesquisados.
     * Delega a busca ao price_researcher via HTTP.
     *
     * Distingue dois casos que antes eram indistinguíveis via steamcharts_id IS NULL:
     *   - Nunca buscado:     steamcharts_id IS NULL AND steamcharts_searched_at IS NULL
     *   - Buscado, não achou: steamcharts_id IS NULL AND steamcharts_searched_at NOT NULL
     *
     * Ao concluir com sucesso, marca todos os jogos enviados com steamcharts_searched_at,
     * evitando que o cron reprocesse indefinidamente jogos ausentes no Steamcharts.
     * Em caso de falha HTTP, não marca — não sabemos o resultado da busca.
     */
    public function searchGamesIdSteam(): void
    {
        $games = Game::whereNull('steamcharts_id')
            ->whereNull('steamcharts_searched_at')
            ->select('id', 'name')
            ->get()
            ->map(fn ($game) => ['id' => $game->id, 'name' => $game->name])
            ->all();

        if (empty($games)) {
            return;
        }

        $response = Http::timeout(3200)->post(
            config('services.price_researcher.base_url').'/api/games/search-id-steam',
            ['games' => $games]
        );

        if (! $response->successful() || ! ($response->json()['success'] ?? false)) {
            Log::error('Erro na requisição do Price Researcher: '.$response->status().' - '.$response->body());
            Mail::raw('Erro na requisição do Price Researcher: '.$response->body(), function ($message) use ($response) {
                $message->to('carcadeals@gmail.com')
                    ->subject('Erro na requisição do Price Researcher: '.$response->status());
            });

            return;
        }

        $data = $response->json();

        // Marca todos os jogos enviados como pesquisados, independente de terem sido encontrados
        $sentIds = array_column($games, 'id');
        Game::whereIn('id', $sentIds)->update(['steamcharts_searched_at' => now()]);

        $updates = collect($data['data']['games'])
            ->filter(fn ($game) => isset($game['id_steam']))
            ->map(fn ($game) => ['id' => $game['id'], 'steamcharts_id' => $game['id_steam']])
            ->values()
            ->all();

        if (! empty($updates)) {
            Game::upsert($updates, uniqueBy: ['id'], update: ['steamcharts_id']);
        }

        Log::info('Id Steam dos jogos atualizados com sucesso: '.count($updates));
    }

    /**
     * Atualiza o preço mínimo da API Gamivo para keys antigas ainda listadas.
     * Processa até 10 keys por chamada para evitar sobrecarga.
     * A regra de degradação por tempo vive em Domain/Keys/KeyPriceAging.
     */
    public function updateMinPrices(): void
    {
        $keys = Key::select('id', 'game_name', 'region', 'individual_cost', 'min_api', 'max_api', 'listed_at', 'sold_at')
            ->whereNotNull('listed_at')
            ->whereNull('sold_at')
            ->limit(10)
            ->get();

        foreach ($keys as $key) {
            $monthsListed = Carbon::parse($key->listed_at)->diffInMonths(now());
            $newMinPrice = KeyPriceAging::calculateAgedPrice((float) $key->individual_cost, $monthsListed);

            if ($newMinPrice === null) {
                continue; // Ainda não atingiu nenhum tier — sem alteração
            }

            $key->min_api = $newMinPrice;
            $key->save();
        }
    }
}
