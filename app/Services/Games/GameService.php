<?php

namespace App\Services\Games;

use App\Domain\Keys\KeyPriceAging;
use App\Models\Game;
use App\Models\Venda_chave_troca;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Infraestrutura para operações sobre jogos.
 *
 * Responsabilidades:
 *  - Lookup de idGamivo (em venda_chave_trocas e games)
 *  - Preenchimento de idGamivo na tabela games
 *  - Criação de jogo na tabela games (quando não existe)
 *  - Busca de IDs no Steamcharts via price_researcher
 *  - Atualização de preço mínimo da API Gamivo por envelhecimento (delega regra ao Domain)
 */
class GameService
{
    /**
     * Procura o idGamivo pelo nome do jogo e região.
     * Busca primeiro nas keys existentes, depois na tabela games.
     */
    public function getIdGamivo(string $gameName, ?string $region): string|false
    {
        $key = Venda_chave_troca::select('idGamivo')
            ->whereRaw('LOWER("nomeJogo") = LOWER(?)', [$gameName])
            ->where('region', $region)
            ->whereNotNull('idGamivo')
            ->first();

        if ($key) {
            return $key->idGamivo;
        }

        $game = Game::select('id_gamivo')
            ->whereRaw('LOWER("name") = LOWER(?)', [$gameName])
            ->where('region', $region)
            ->whereNotNull('id_gamivo')
            ->first();

        return $game?->id_gamivo ?? false;
    }

    /**
     * Preenche o idGamivo na tabela games quando ainda não está cadastrado.
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
     * @param  array{nomeJogo: string, region: string|null, idGamivo: string|null}  $game
     */
    public function createGameIfDontExists(array $game): void
    {
        Game::whereRaw('LOWER("name") = LOWER(?)', [$game['nomeJogo']])
            ->where('region', $game['region'])
            ->firstOr(fn () => Game::create([
                'name' => $game['nomeJogo'],
                'region' => $game['region'],
                'id_gamivo' => $game['idGamivo'],
            ]));
    }

    /**
     * Busca IDs do Steamcharts para jogos que ainda não possuem o campo preenchido.
     * Delega a busca ao price_researcher via HTTP.
     */
    public function searchGamesIdSteam(): void
    {
        $games = Game::whereNull('id_steamcharts')
            ->select('id', 'name')
            ->get()
            ->map(fn ($game) => ['id' => $game->id, 'name' => $game->name])
            ->all();

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

        foreach ($data['data']['games'] as $foundGame) {
            if (! isset($foundGame['id_steam'])) {
                continue;
            }
            Game::where('id', $foundGame['id'])->update(['id_steamcharts' => $foundGame['id_steam']]);
        }

        Log::info('Id Steam dos jogos atualizados com sucesso: '.count($data['data']['games']));
    }

    /**
     * Atualiza o preço mínimo da API Gamivo para keys antigas ainda listadas.
     * Processa até 10 keys por chamada para evitar sobrecarga.
     * A regra de degradação por tempo vive em Domain/Keys/KeyPriceAging.
     */
    public function updateMinPrices(): void
    {
        $keys = Venda_chave_troca::select('id', 'nomeJogo', 'region', 'valorPagoIndividual', 'minApiGamivo', 'maxApiGamivo', 'dataVenda', 'dataVendida')
            ->whereNotNull('dataVenda')
            ->whereNull('dataVendida')
            ->limit(10)
            ->get();

        foreach ($keys as $key) {
            $monthsListed = Carbon::parse($key->dataVenda)->diffInMonths(now());
            $newMinPrice = KeyPriceAging::calculateAgedPrice((float) $key->valorPagoIndividual, $monthsListed);

            if ($newMinPrice === null) {
                continue; // Ainda não atingiu nenhum tier — sem alteração
            }

            $key->minApiGamivo = $newMinPrice;
            $key->save();
        }
    }
}
