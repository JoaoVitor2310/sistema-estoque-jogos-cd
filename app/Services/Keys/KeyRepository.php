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
     * Retorna keys elegíveis para listagem automática no Gamivo.
     *
     * Regras aplicadas via local scopes (ver Key):
     *  - withGamivoId: gamivo_id preenchido
     *  - notYetListed: listed_at e sold_at nulas
     *  - notGiftLink: key_code sem URL
     *  - withoutRecentBundle: jogo fora de bundles dos últimos N dias
     *
     * Eager loading traz o jogo e seus bundles ordenados por release_date desc,
     * permitindo que o UseCase selecione o bundle mais recente (um por key).
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
            ->with(['game.bundles' => fn ($q) => $q->latest('release_date')])
            ->get();
    }
}
