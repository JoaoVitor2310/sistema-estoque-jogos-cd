<?php

namespace App\Services\Games;

use App\Domain\Games\GiveawayPolicy;
use App\Models\Game;
use App\Models\Giveaway;
use Carbon\Carbon;

/**
 * Leitura dos giveaways registrados, já projetados para consulta.
 *
 * Sem paginação nem filtro no servidor: com o feed restrito a jogo Steam, são
 * poucas dezenas de giveaways por ano, e separar "em giveaway hoje" do
 * histórico é recorte de quem lê, não consulta.
 */
class GiveawayRepository
{
    /**
     * Todos os giveaways, do publicado mais recente para o mais antigo.
     *
     * @return list<array{id: int, title: string, game_name: string|null, store: string|null, worth: string|null, gamerpower_url: string, published_date: string, end_date: string|null, status: string, last_seen_at: string, duration_days: int, is_flagged: bool, flagged_until: string|null, games: list<array{id: int, name: string, region: string|null}>}>
     */
    public function forListing(?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        return Giveaway::with(['games' => fn ($query) => $query->select('games.id', 'games.name', 'games.region')->orderBy('games.region')])
            ->orderByDesc('published_date')
            ->orderByDesc('id')
            ->get()
            ->map(function (Giveaway $giveaway) use ($now) {
                $period = $giveaway->period();

                return [
                    'id' => $giveaway->id,
                    'title' => $giveaway->title,
                    'game_name' => $giveaway->game_name,
                    'store' => $giveaway->store,
                    'worth' => $giveaway->worth,
                    'gamerpower_url' => $giveaway->gamerpower_url,
                    // Datas como texto `dd/mm/aaaa`, como o resto do sistema exibe.
                    'published_date' => $giveaway->published_date->format('d/m/Y'),
                    'end_date' => $giveaway->end_date?->format('d/m/Y'),
                    'status' => $giveaway->status->value,
                    'last_seen_at' => $giveaway->last_seen_at->format('d/m/Y H:i'),
                    'duration_days' => GiveawayPolicy::durationDays($period),
                    'is_flagged' => GiveawayPolicy::isFlagged($period, $now),
                    // Null enquanto ativo: não há prazo enquanto ele está no feed.
                    'flagged_until' => GiveawayPolicy::flaggedUntil($period)?->format('d/m/Y'),
                    'games' => $giveaway->games
                        ->map(fn (Game $game) => ['id' => $game->id, 'name' => $game->name, 'region' => $game->region])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }
}
