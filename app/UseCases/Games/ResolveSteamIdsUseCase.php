<?php

namespace App\UseCases\Games;

use App\Mail\SteamIdSearchFailedMail;
use App\Models\Game;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Descobre o `steam_id` dos jogos que ainda não têm um, delegando a busca ao
 * price_researcher.
 *
 * Distingue dois casos que a coluna `steam_id` sozinha não separava:
 *  - **nunca procurado** (`steamcharts_searched_at` nula) — elegível para busca
 *  - **procurado sem sucesso** — o SteamCharts não conhece o jogo, e procurar
 *    de novo não muda nada
 *
 * Sem essa distinção o scheduler reprocessaria para sempre os mesmos jogos
 * ausentes. Por isso todo jogo enviado é marcado como pesquisado, tenha sido
 * encontrado ou não — mas só quando a requisição volta bem-sucedida: se a
 * chamada falha, não sabemos o resultado da busca e nada é marcado.
 *
 * Roda no scheduler diário (ver routes/console.php).
 */
class ResolveSteamIdsUseCase
{
    /**
     * Teto de espera do price_researcher: a busca varre o SteamCharts jogo a
     * jogo, então um lote grande leva muitos minutos.
     */
    public const REQUEST_TIMEOUT_SECONDS = 3200;

    /**
     * @return int quantidade de jogos que ganharam `steam_id`
     */
    public function execute(): int
    {
        $games = Game::whereNull('steam_id')
            ->whereNull('steamcharts_searched_at')
            ->select('id', 'name')
            ->get()
            ->map(fn (Game $game) => ['id' => $game->id, 'name' => $game->name])
            ->all();

        if (empty($games)) {
            return 0;
        }

        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)->post(
                config('services.price_researcher.base_url').'/api/games/search-id-steam',
                ['games' => $games]
            );
        } catch (ConnectionException $e) {
            // Serviço fora do ar não devolve status: a exceção é lançada antes de
            // existir resposta para inspecionar. Sem este catch ela sobe e o alerta
            // nunca sai — justamente no modo de falha mais provável dos dois.
            $this->reportFailure('conexão falhou', $e->getMessage());

            return 0;
        }

        // `data.games` ausente conta como falha: uma resposta que não dá para ler
        // não prova que os jogos não existem no SteamCharts, e marcá-los aqui os
        // aposentaria para sempre.
        $found = $response->successful() && ($response->json()['success'] ?? false)
            ? data_get($response->json(), 'data.games')
            : null;

        if (! is_array($found)) {
            $this->reportFailure('HTTP '.$response->status(), $response->body());

            return 0;
        }

        $updates = collect($found)
            ->filter(fn (array $game) => isset($game['id_steam']))
            ->map(fn (array $game) => ['id' => $game['id'], 'steam_id' => $game['id_steam']])
            ->values()
            ->all();

        // Marca depois de ler: só aqui sabemos que a busca de fato aconteceu.
        Game::whereIn('id', array_column($games, 'id'))
            ->update(['steamcharts_searched_at' => now()]);

        if (! empty($updates)) {
            Game::upsert($updates, uniqueBy: ['id'], update: ['steam_id']);
        }

        Log::info('Steam IDs resolved: '.count($updates));

        return count($updates);
    }

    /**
     * Registra e avisa que a busca não aconteceu.
     *
     * Falha de envio não pode derrubar o scheduler (ver AlertExpiringKeysUseCase).
     *
     * @param  string  $summary  causa em uma linha, usada no assunto do e-mail
     * @param  string  $detail  corpo da resposta ou mensagem da exceção
     */
    private function reportFailure(string $summary, string $detail): void
    {
        Log::error('Price Researcher steam-id search failed: '.$summary.' - '.$detail);

        try {
            Mail::to(config('app.admin_email'))->send(new SteamIdSearchFailedMail($summary, $detail));
        } catch (\Exception $e) {
            Log::error('Failed to send steam-id search alert: '.$e->getMessage());
        }
    }
}
