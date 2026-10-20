<?php

namespace App\Services\Games;

use App\Domain\Games\GameNameNormalizer;
use App\Domain\Games\GiveawayPolicy;
use App\Domain\Trades\TradeLineFlags;
use App\Models\Giveaway;
use Carbon\Carbon;

/**
 * Quais jogos estão em giveaway agora, para gravar a marca nas linhas de trade.
 *
 * O casamento é pelo nome normalizado direto contra `giveaways`, **não** pela
 * pivot `giveaway_games`: o jogo que nunca tivemos não tem linha em `games`, e
 * é justamente o que o fornecedor costuma oferecer. Quem decide se o jogo está
 * em giveaway — basta um giveaway na janela, sem olhar a região — é
 * [[GiveawayPolicy::isGameFlagged]]; aqui só se busca e se molda o resultado.
 */
class GiveawayService
{
    /**
     * Marca `is_giveaway` em cada jogo pesquisado.
     *
     * Viaja junto do jogo, como a marca de encalhe, porque a oferta ao supplier
     * e a linha da trade a leem na mesma passada. Uma consulta só para o lote.
     *
     * @param  list<array{name: string, ...}>  $games  saída do price_researcher
     * @return list<array{name: string, is_giveaway: bool, ...}>
     */
    public function markResearched(array $games, ?Carbon $now = null): array
    {
        $flagged = $this->flaggedNames(array_column($games, 'name'), $now ?? Carbon::now());

        return array_map(fn (array $game) => $game + [
            TradeLineFlags::GIVEAWAY => filled($game['name'] ?? null)
                && isset($flagged[GameNameNormalizer::normalize($game['name'])]),
        ], $games);
    }

    /** O jogo desta linha está em giveaway? É a consulta de uma linha só — a da edição. */
    public function isGiveaway(?string $gameName, ?Carbon $now = null): bool
    {
        if (blank($gameName)) {
            return false;
        }

        return $this->flaggedNames([$gameName], $now ?? Carbon::now()) !== [];
    }

    /**
     * Os nomes normalizados, dentre os pedidos, de jogos em giveaway.
     *
     * @param  array<int, string|null>  $gameNames
     * @return array<string, true>
     */
    private function flaggedNames(array $gameNames, Carbon $now): array
    {
        $normalized = array_values(array_unique(array_map(
            fn (string $name) => GameNameNormalizer::normalize($name),
            array_filter($gameNames, fn (?string $name) => filled($name)),
        )));

        if ($normalized === []) {
            return [];
        }

        return Giveaway::whereIn('normalized_game_name', $normalized)
            ->flaggableAt($now)
            ->get()
            ->groupBy('normalized_game_name')
            ->filter(fn ($giveaways) => GiveawayPolicy::isGameFlagged($giveaways->map->period(), $now))
            ->map(fn () => true)
            ->all();
    }
}
