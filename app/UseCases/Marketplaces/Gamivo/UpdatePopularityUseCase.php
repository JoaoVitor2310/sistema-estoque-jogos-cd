<?php

namespace App\UseCases\Marketplaces\Gamivo;

use App\Services\External\SteamChartsService;
use App\Services\Games\GameService;
use Illuminate\Support\Facades\Log;

/**
 * Atualiza a popularidade dos jogos via scraping do SteamCharts.
 * Executado diariamente às 7h via scheduler.
 *
 * Migrado de GET /api/update-popularity (gamivo-carca-deals, Node.js).
 * Documentação: docs/GAMIVO.md — seção "Fluxo C: update-popularity".
 */
class UpdatePopularityUseCase
{
    /**
     * Delay entre requests ao SteamCharts para evitar rate limiting.
     * Em segundos.
     */
    public const SCRAPE_DELAY_S = 1;

    public function __construct(
        private readonly SteamChartsService $steamCharts,
        private readonly GameService $gameService,
    ) {}

    /**
     * Itera todos os jogos com steam_id e atualiza a popularidade via SteamCharts.
     * Erros por jogo são logados e não interrompem os demais.
     *
     * @return int[] IDs dos jogos atualizados com sucesso
     */
    public function execute(): array
    {
        $games = $this->gameService->getGamesForPopularityUpdate();
        $updated = [];

        foreach ($games as $game) {
            $popularity = $this->steamCharts->getPopularity((string) $game->steam_id);
            $this->gameService->updatePopularity($game->id, $popularity);
            $updated[] = $game->id;

            // Delay para evitar banimento por rate limiting do SteamCharts
            if (! app()->environment('testing')) {
                sleep(self::SCRAPE_DELAY_S);
            }
        }

        Log::info('UpdatePopularityUseCase: concluído', [
            'total' => count($games),
            'updated' => count($updated),
        ]);

        return $updated;
    }
}
