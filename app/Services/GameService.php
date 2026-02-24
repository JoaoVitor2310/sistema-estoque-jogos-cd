<?php

namespace App\Services;

use App\Models\Fornecedor;
use App\Models\Game;
use App\Models\Venda_chave_troca;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class GameService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Procura se o id Gamivo já está cadastrado na tabela Games ou na tabela Venda_chave_troca
     * @param string $nomeJogo
     * @param string|null $region
     * @return string|false
     */
    public function getIdGamivo(string $nomeJogo, string | null $region)
    {
        // Procura nas keys da tabela venda-chave-troca
        $game = Venda_chave_troca::select('idGamivo')->whereRaw('LOWER("nomeJogo") = LOWER(?)', [$nomeJogo])->where('region', $region)->whereNotNull('idGamivo')->first();
        if ($game) return $game->idGamivo;

        // Procura nos dados de jogos gerais
        $game = Game::select('id_gamivo')->whereRaw('LOWER("name") = LOWER(?)', [$nomeJogo])->where('region', $region)->whereNotNull('id_gamivo')->first();
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
        $game = Game::whereRaw('LOWER("name") = LOWER(?)', [$gameName])->where('region', $region)->whereNull('id_gamivo')->first();
        if ($game) {
            $game->id_gamivo = $idGamivo;
            $game->save();
        }
    }

    public function createGameIfDontExists($game)
    {
        $exists = Game::whereRaw('LOWER("name") = LOWER(?)', [$game['nomeJogo']])->where('region', $game['region'])->first();
        if (!$exists) {
            Game::create([
                'name' => $game['nomeJogo'],
                'region' => $game['region'],
                'id_gamivo' => $game['idGamivo'],
            ]);
        }

        return;
    }

    public function searchGamesIdSteam()
    {
        $games = Game::whereNull('id_steamcharts')->select('id', 'name')->get();
        $gamesArray = $games->map(function ($game) {
            return [
                'id' => $game->id,
                'name' => $game->name,
            ];
        })->toArray();

        $response = Http::timeout(3200)->post(
            config('services.price_researcher.base_url') . '/api/games/search-id-steam',
            [
                'games' => $gamesArray,
            ]
        );

        $data = $response->json();
        if (!$response->successful() || !$response['success']) {
            Log::error('Erro na requisição do Price Researcher: ' . $response->status() . ' - ' . $response->body() . ' | Arquivo: ' . $response->getFile() . ' | Linha: ' . $response->getLine());
            // Enviar email?
            Mail::raw('Erro na requisição do Price Researcher: ' . $response->body(), function ($message) use ($response) {
                $message->to('carcadeals@gmail.com')
                    ->subject('Erro na requisição do Price Researcher: ' . $response->body());
            });
        }

        foreach ($data['data']['games'] as $foundGame) {
            if (!isset($foundGame['id_steam'])) continue;
            Game::where('id', $foundGame['id'])->update(['id_steamcharts' => $foundGame['id_steam']]);
        }
        Log::info('Id Steam dos jogos atualizados com sucesso: ' . count($data['data']['games']));
    }

    /**
     * Update the minimum API Gamivo for old selling games
     * @return void
     */
    public function updateMinPrices(): void
    {
        $keys = Venda_chave_troca::select('id', 'nomeJogo', 'region', 'valorPagoIndividual', 'minApiGamivo', 'maxApiGamivo', 'dataVenda', 'dataVendida')->whereNotNull('dataVenda')->whereNull('dataVendida')->limit(10)->get();
        foreach ($keys as $key) {
            $today = now();

            $dataVenda = Carbon::parse($key->dataVenda);

            if ($dataVenda->diffInMonths($today) >= 12) {
                $key->minApiGamivo = 0.02;
            } else if ($dataVenda->diffInMonths($today) >= 9) {
                $key->minApiGamivo = $key->valorPagoIndividual * 1.2;
            } else if ($dataVenda->diffInMonths($today) >= 6) {
                $key->minApiGamivo = $key->valorPagoIndividual * 1.3;
            } else if ($dataVenda->diffInMonths($today) >= 3) {
                $key->minApiGamivo = $key->valorPagoIndividual * 1.4;
            }
            $key->save();
        }
    }

    /**
     * Identifica a plataforma do jogo baseado no padrão da chave
     */
    public function identifyPlatform($chaveRecebida)
    {
        $patterns = [
            'Steam' => '/^\w{5}-\w{5}-\w{5}$|^\w{15}\s\w{2}$/',
            'EA' => '/^\w{4}-\w{4}-\w{4}-\w{4}-\w{4}$/',
            'EA/Ubisoft' => '/^\w{4}-\w{4}-\w{4}-\w{4}$/',
            'EGS' => '/^\w{5}-\w{5}-\w{5}-\w{5}$/',
            'GOG' => '/^\w{18}$/',
            'XBOX' => '/^\w{5}-\w{5}-\w{5}-\w{5}-\w{5}$/',
            'PSN' => '/^\w{4}-\w{4}-\w{4}$/',
        ];

        foreach ($patterns as $platform => $pattern) {
            if (preg_match($pattern, $chaveRecebida)) {
                return $platform;
            }
        }

        return 'DESCONHECIDO';
    }

    /**
     * Cria ou adiciona reclamação ao fornecedor
     */
    public function criarAdicionarFornecedor($perfilOrigem, $reclamacao)
    {
        $fornecedor = Fornecedor::where('perfilOrigem', $perfilOrigem)->first();

        if (!$fornecedor) {
            // Se não tiver o fornecedor, cria ele
            $newFornecedor = ['perfilOrigem' => $perfilOrigem];

            if ($reclamacao != 1) {
                $newFornecedor['quantidade_reclamacoes'] = 1;
            }

            $fornecedor = Fornecedor::create($newFornecedor);
        } else {
            // Existe o fornecedor, soma mais uma reclamação se tiver
            if ($reclamacao != 1) {
                $fornecedor->where('perfilOrigem', $perfilOrigem)
                    ->update(['quantidade_reclamacoes' => $fornecedor->quantidade_reclamacoes + 1]);
            }
        }

        return $fornecedor->id;
    }
}
