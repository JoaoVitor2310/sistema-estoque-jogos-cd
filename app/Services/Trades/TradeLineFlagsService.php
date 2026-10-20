<?php

namespace App\Services\Trades;

use App\Domain\Trades\TradeLineFlags;
use App\Services\Games\GiveawayService;
use Carbon\Carbon;

/**
 * Avalia todas as marcas de linha ([[TradeLineFlags]]) de uma vez.
 *
 * É o ponto único por onde quem monta oferta ou linha pede as marcas: uma
 * marca nova entra aqui e em [[TradeLineFlags::COLUMNS]], sem tocar em cada
 * caminho de escrita. Cada marca continua decidida pelo serviço dela.
 */
class TradeLineFlagsService
{
    public function __construct(
        private readonly OverstockService $overstockService,
        private readonly GiveawayService $giveawayService,
    ) {}

    /**
     * Marca cada jogo pesquisado com todas as marcas, uma consulta por marca
     * para o lote inteiro.
     *
     * @param  list<array{name: string, ...}>  $games  saída do price_researcher
     * @return list<array<string, mixed>> os jogos com `is_overstocked` e `is_giveaway`
     */
    public function markResearched(array $games, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        return $this->giveawayService->markResearched(
            $this->overstockService->markResearched($games, $now),
            $now,
        );
    }

    /**
     * As marcas de uma linha só — a que nasce com jogo ou troca de jogo.
     *
     * @return array<string, bool> atributos de `trade_lines`
     */
    public function flagsFor(?string $gameName, ?string $region, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        return [
            TradeLineFlags::OVERSTOCKED => $this->overstockService->isOverstocked($gameName, $region, $now),
            TradeLineFlags::GIVEAWAY => $this->giveawayService->isGiveaway($gameName, $now),
        ];
    }
}
