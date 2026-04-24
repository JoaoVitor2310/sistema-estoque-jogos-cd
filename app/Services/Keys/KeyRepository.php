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
     * Retorna keys elegíveis para listagem automática no Gamivo.
     *
     * Regras aplicadas via local scopes (ver Key):
     *  - registeredOnGamivo: gamivo_id preenchido
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
            ->registeredOnGamivo()
            ->notYetListed()
            ->notGiftLink()
            ->withoutRecentBundle(KeyEligibility::BUNDLE_EXCLUSION_DAYS)
            ->with(['game.bundles' => fn ($q) => $q->latest('release_date')])
            ->get();
    }
}
