<?php

namespace App\Services\Trades;

use App\Domain\Trades\GameStock;
use App\Domain\Trades\OverstockPolicy;
use App\Services\Keys\KeyRepository;
use Carbon\Carbon;

/**
 * O estoque encalhado, lido de dois jeitos: por linha de trade, para gravar a
 * marca, e como lista de todo jogo encalhado hoje, com os números que o
 * condenam.
 *
 * Existe para a query e a regra se encontrarem num lugar só. Quem decide
 * continua sendo [[App\Domain\Trades\OverstockPolicy]]; aqui só se busca o
 * estoque e se molda o resultado.
 */
class OverstockService
{
    public function __construct(
        private readonly KeyRepository $keyRepository,
    ) {}

    /**
     * Marca `is_overstocked` em cada jogo pesquisado.
     *
     * A marca viaja junto do jogo, e não da linha já montada, porque dois
     * consumidores precisam dela na mesma passada: a oferta ao supplier, que
     * **exclui** o jogo encalhado do comentário, e a linha da trade, que o
     * mantém com o aviso ([[App\Domain\Trades\TradeLineBuilder]] copia a marca).
     * Uma agregação só serve aos dois.
     *
     * @param  list<array{name: string, ...}>  $games  saída do price_researcher
     * @return list<array{name: string, is_overstocked: bool, ...}>
     */
    public function markResearched(array $games, ?Carbon $now = null): array
    {
        // Sem nome não há jogo para procurar, e a agregação sairia de graça.
        $named = array_filter($games, fn (array $game) => filled($game['name'] ?? null));

        if ($named === []) {
            return array_map(fn (array $game) => $game + [OverstockPolicy::FLAG_COLUMN => false], $games);
        }

        $now ??= Carbon::now();
        $stock = $this->keyRepository->stockByGameIdentity($now);

        return array_map(fn (array $game) => $game + [
            OverstockPolicy::FLAG_COLUMN => $this->isInOverstockedGroup($game['name'] ?? null, $game['region'] ?? null, $stock, $now),
        ], $games);
    }

    /**
     * O jogo desta linha está encalhado na região dela?
     *
     * É a consulta de uma linha só — a da edição, quando nome ou região mudam.
     */
    public function isOverstocked(?string $gameName, ?string $region, ?Carbon $now = null): bool
    {
        if (blank($gameName)) {
            return false;
        }

        $now ??= Carbon::now();

        return $this->isInOverstockedGroup($gameName, $region, $this->keyRepository->stockByGameIdentity($now), $now);
    }

    /**
     * Todo jogo encalhado hoje, do estoque maior para o menor.
     *
     * Nada é persistido: o jogo sai da lista assim que as keys vendem ou o
     * ritmo dele melhora.
     *
     * @return list<array{name: string, region: string|null, stock: int, days_in_stock: int|null, sold_in_window: int, coverage_days: int|null}>
     */
    public function overstockedGames(?Carbon $now = null): array
    {
        // Um relógio só para a passada inteira: com `now()` relido a cada jogo,
        // a lista poderia sinalizar um jogo por idade e informar outra idade.
        $now ??= Carbon::now();

        $overstocked = [];

        foreach ($this->keyRepository->stockByGameIdentity($now) as $game) {
            if (! OverstockPolicy::isOverstocked($game, $now)) {
                continue;
            }

            $overstocked[] = [
                'name' => $game->displayName,
                'region' => $game->region,
                'stock' => $game->stock,
                'days_in_stock' => $game->daysInStock($now),
                'sold_in_window' => $game->soldInWindow,
                'coverage_days' => $this->wholeCoverageDays($game),
            ];
        }

        usort($overstocked, fn (array $a, array $b) => $b['stock'] <=> $a['stock']);

        return $overstocked;
    }

    /**
     * Cobertura em dias inteiros, arredondada para cima: um jogo marcado por
     * 120,4 dias aparece com 121, nunca com um 120 que contradiz o limite.
     */
    private function wholeCoverageDays(GameStock $game): ?int
    {
        $coverage = OverstockPolicy::coverageDays($game);

        return $coverage === null ? null : (int) ceil($coverage);
    }

    /**
     * @param  array<string, GameStock>  $stock
     */
    private function isInOverstockedGroup(?string $gameName, ?string $region, array $stock, Carbon $now): bool
    {
        if (blank($gameName)) {
            return false;
        }

        $game = $stock[GameStock::identityOf($gameName, $region)] ?? null;

        return $game !== null && OverstockPolicy::isOverstocked($game, $now);
    }
}
