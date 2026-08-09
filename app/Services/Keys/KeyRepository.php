<?php

namespace App\Services\Keys;

use App\Domain\Keys\KeyEligibility;
use App\Models\Key;
use Illuminate\Database\Eloquent\Collection;

/**
 * Queries complexas sobre a tabela keys.
 * Infraestrutura pura — sem lógica de negócio.
 */
class KeyRepository
{
    /**
     * Busca uma key pelo código de ativação.
     * Quando $excludeId é fornecido, ignora o próprio registro (útil no update).
     */
    public function findByKeyCode(string $keyCode, ?int $excludeId = null): ?Key
    {
        return Key::where('key_code', $keyCode)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->first();
    }

    /**
     * Retorna todas as keys vinculadas a uma trade (mesmo lote), ordenadas por id ASC.
     * Usado no recálculo do rateio de custo ao editar uma key. Ver docs/adr/0004.
     *
     * @return Collection<int, Key>
     */
    public function findByTradeId(int $tradeId): Collection
    {
        return Key::where('trade_id', $tradeId)
            ->orderBy('id')
            ->get();
    }

    /**
     * Retorna a key governante de um produto Gamivo: entre as listadas e ainda não
     * vendidas que compartilham o mesmo gamivo_id, a mais antiga na oferta — quem a
     * Gamivo vende primeiro (FIFO). Usada pelo UpdateOffersUseCase tanto para o clamp
     * de min_api/max_api quanto para o game_name do log, com uma query só.
     *
     * Ordena por listed_at ASC, com id ASC como desempate: listed_at é uma coluna
     * `date` (sem hora), então keys confirmadas no mesmo lote do AutoSellUseCase
     * empatam na mesma data — nesse caso, a de menor id foi enviada primeiro no
     * uploadKeys em lote (ver AutoSellUseCase::processGroup). Ver docs/adr/0006.
     *
     * min_api/max_api são NOT NULL desde 2026-08-08 — toda key nasce com os dois
     * calculados (RegisterKeyUseCase), então o retorno aqui sempre traz valores
     * concretos, nunca precisando de fallback.
     */
    public function findGoverningKeyByGamivoId(int $productId): ?Key
    {
        return Key::where('gamivo_id', (string) $productId)
            ->whereNotNull('listed_at')
            ->whereNull('sold_at')
            ->orderBy('listed_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * Retorna keys elegíveis para listagem automática no Gamivo (AutoSellUseCase).
     *
     * Regras aplicadas via local scopes (ver Key):
     *  - withGamivoId: gamivo_id preenchido
     *  - notYetListed: listed_at e sold_at nulas
     *  - notGiftLink: key_code sem URL
     *  - withoutRecentBundle: jogo fora de bundles dos últimos 21 dias
     *
     * Ordenadas por id ASC: a Gamivo vende FIFO (key mais antiga primeiro), e o
     * AutoSellUseCase agrupa por gamivo_id usando a key de menor id como governante.
     *
     * @return Collection<int, Key>
     */
    public function findEligibleForAutoSell(): Collection
    {
        return Key::query()
            ->withGamivoId()
            ->notYetListed()
            ->notGiftLink()
            ->withoutRecentBundle(KeyEligibility::BUNDLE_EXCLUSION_DAYS)
            ->with('game.latestBundle')
            ->orderBy('id')
            ->get();
    }
}
