<?php

namespace App\UseCases\Games;

use App\Domain\Enums\GiveawayStatus;
use App\Domain\Games\GameNameNormalizer;
use App\Domain\Games\GiveawayPeriod;
use App\Domain\Games\GiveawayPolicy;
use App\Domain\Games\GiveawayTitle;
use App\Models\Game;
use App\Models\Giveaway;
use App\Services\External\GamerPowerApiService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Registra os giveaways de jogo Steam do GamerPower e os vincula aos jogos.
 *
 * A API só mostra o que está ativo agora, então o histórico é construído
 * aqui, uma passada de cada vez:
 *
 *  1. Giveaway que veio no feed é criado ou atualizado, fica `active` e tem o
 *     `last_seen_at` renovado — inclusive um que tinha encerrado e voltou.
 *  2. Giveaway `active` que não veio passa a `ended`; o `last_seen_at` fica
 *     como estava, e é ele que marca o fim real.
 *  3. Todo giveaway ainda na janela de [[GiveawayPolicy]] é revinculado às
 *     linhas de `games` com o mesmo nome normalizado — uma por região, as que
 *     existirem. Revincular a cada passada pega a região cadastrada depois do
 *     giveaway e solta o jogo que foi renomeado. Fora da janela o vínculo fica
 *     congelado como histórico.
 *
 * Consulta que falha não muda nada: encerrar os giveaways porque a API caiu
 * encurtaria a janela de todos eles.
 *
 * Jogo que nunca tivemos não ganha linha em `games`: o giveaway fica sem
 * vínculo até o jogo aparecer. Ver docs/adr/0014.
 */
class SyncGiveawaysUseCase
{
    public function __construct(
        private readonly GamerPowerApiService $gamerPowerApi,
    ) {}

    public function execute(?Carbon $now = null): void
    {
        $now ??= Carbon::now();

        $active = $this->gamerPowerApi->activeSteamGames();

        if ($active === null) {
            Log::warning('Giveaway sync skipped: GamerPower API request failed');

            return;
        }

        DB::transaction(function () use ($active, $now) {
            foreach ($active as $item) {
                $this->upsert($item, $now);
            }

            Giveaway::where('status', GiveawayStatus::Active)
                ->whereNotIn('gamerpower_id', array_column($active, 'gamerpower_id'))
                ->update(['status' => GiveawayStatus::Ended]);

            $this->relinkFlagged($now);
        });
    }

    /**
     * @param  array{gamerpower_id: int, title: string, worth: ?float, gamerpower_url: string, published_date: ?Carbon, end_date: ?Carbon}  $item
     */
    private function upsert(array $item, Carbon $now): void
    {
        $gameName = GiveawayTitle::gameName($item['title']);

        if ($gameName === null) {
            Log::warning('Giveaway title does not match the known pattern; saved without a game', [
                'gamerpower_id' => $item['gamerpower_id'],
                'title' => $item['title'],
            ]);
        }

        $giveaway = Giveaway::firstOrNew(['gamerpower_id' => $item['gamerpower_id']]);

        $giveaway->fill([
            'title' => $item['title'],
            'game_name' => $gameName,
            'normalized_game_name' => $gameName === null ? null : GameNameNormalizer::normalize($gameName),
            'store' => GiveawayTitle::store($item['title']),
            'worth' => $item['worth'],
            'gamerpower_url' => $item['gamerpower_url'],
            'published_date' => GiveawayPeriod::startDate($item['published_date'], $giveaway->published_date, $now),
            'end_date' => $item['end_date'],
            'status' => GiveawayStatus::Active,
            'last_seen_at' => $now,
        ])->save();
    }

    private function relinkFlagged(Carbon $now): void
    {
        $flagged = Giveaway::whereNotNull('normalized_game_name')
            ->where(fn ($query) => $query
                ->where('status', GiveawayStatus::Active)
                ->orWhere('last_seen_at', '>=', GiveawayPolicy::oldestFlaggableLastSeen($now)))
            ->get()
            ->filter(fn (Giveaway $giveaway) => GiveawayPolicy::isFlagged($giveaway->period(), $now));

        if ($flagged->isEmpty()) {
            return;
        }

        $gameIdsByName = Game::whereIn('normalized_name', $flagged->pluck('normalized_game_name')->unique())
            ->get(['id', 'normalized_name'])
            ->groupBy('normalized_name')
            ->map(fn ($games) => $games->pluck('id')->all());

        foreach ($flagged as $giveaway) {
            $giveaway->games()->sync($gameIdsByName[$giveaway->normalized_game_name] ?? []);
        }
    }
}
