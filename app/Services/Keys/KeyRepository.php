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
     * Retorna os limites de preço min/max para um produto Gamivo.
     * Considera apenas keys listadas (listed_at não nulo) e ainda não vendidas (sold_at nulo).
     * Quando múltiplas keys compartilham o mesmo gamivo_id (cópias do mesmo jogo),
     * usa min(min_api) como piso e max(max_api) como teto.
     *
     * @return array{min_api: float, max_api: float}|null Null se não há keys ativas com esse gamivo_id.
     */
    public function findMinMaxByGamivoId(int $productId): ?array
    {
        $result = Key::where('gamivo_id', (string) $productId)
            ->whereNotNull('listed_at')
            ->whereNull('sold_at')
            ->whereNotNull('min_api')
            ->whereNotNull('max_api')
            ->selectRaw('MIN(min_api) as min_api, MAX(max_api) as max_api')
            ->first();

        if ($result === null || $result->min_api === null) {
            return null;
        }

        return [
            'min_api' => (float) $result->min_api,
            'max_api' => (float) $result->max_api,
        ];
    }

    /**
     * Retorna a primeira key listada e não vendida para um produto Gamivo.
     * Usada para obter game_name e key_code nos logs do UpdateOffersUseCase.
     */
    public function findFirstListedByGamivoId(int $productId): ?Key
    {
        return Key::where('gamivo_id', (string) $productId)
            ->whereNotNull('listed_at')
            ->whereNull('sold_at')
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
